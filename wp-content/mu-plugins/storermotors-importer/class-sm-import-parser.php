<?php
/**
 * SML feed parsing and normalisation.
 *
 * This class is the single source of truth for every value mapping. The
 * sm/vehicle-filters block builds its public dropdowns from live DISTINCT meta
 * with no allowlist of its own, so an unrecognised value must never be written
 * through to the database — it is discarded and logged instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Import_Parser {

    /** Feed transmission codes. The feed cannot express CVT; see below. */
    const TRANSMISSION = [
        'A' => 'Auto',
        'S' => 'Auto',   // sequential / tiptronic
        'T' => 'Auto',
        'M' => 'Manual',
        'C' => 'CVT',
    ];

    const FUEL = [
        'PETROL'        => 'Petrol',
        'DIESEL'        => 'Diesel',
        'HYBRID'        => 'Hybrid',
        'PETROL HYBRID' => 'Hybrid',
        'DIESEL HYBRID' => 'Hybrid',
        'HYBRID-PIP'    => 'Plug-In Hybrid',
        'PHEV'          => 'Plug-In Hybrid',
        'PLUG-IN'       => 'Plug-In Hybrid',
        'PLUG-IN HYBRID'=> 'Plug-In Hybrid',
        'ELECTRIC'      => 'Electric',
        'EV'            => 'Electric',
    ];

    /**
     * Body types. Keyed off <Type>, never <ItemType> — ItemType is dirty in the
     * real feed (CONVERTABLE, HBK, and one row reading "GO KARTS").
     */
    const BODY = [
        'CONVERTIBLE'      => 'Convertible',
        'SUV'              => 'SUV',
        'HATCHBACK'        => 'Hatchback',
        'SEDAN'            => 'Sedan',
        'STATION WAGON'    => 'Station Wagon',
        'STATIONWAGON'     => 'Station Wagon',
        'WAGON'            => 'Station Wagon',
        'COUPE'            => 'Coupe',
        'COUP'             => 'Coupe',
        'UTE'              => 'Ute',
        'UTILITY'          => 'Ute',
        'PICKUP'           => 'Ute',
        'PICK UP'          => 'Ute',
        'TRUCK'            => 'Ute',
        'VAN'              => 'Van',
        'PEOPLE MOVER'     => 'People Mover',
        'LIGHT COMMERCIAL' => 'Light Commercial',
    ];

    /** Tokens that must not be title-cased when tidying names and trims. */
    const ACRONYMS = [
        'AWD', '4WD', '2WD', 'FWD', 'RWD', 'PHEV', 'HEV', 'EV', 'BEV', 'GT', 'GTI',
        'RS', 'SE', 'XV', 'RV', 'SUV', 'TDI', 'TSI', 'VTEC', 'GLS', 'GLX', 'LX',
        'EX', 'DX', 'ST', 'XL', 'XLT', 'SR', 'SR5', 'V6', 'V8', 'S', 'X',
    ];

    /** Makes whose correct casing is not title case. */
    const MAKES = [
        'BMW' => 'BMW', 'MG' => 'MG', 'LDV' => 'LDV', 'GWM' => 'GWM',
        'BYD' => 'BYD', 'MERCEDES-BENZ' => 'Mercedes-Benz', 'VOLKSWAGEN' => 'Volkswagen',
        'BMW ALPINA' => 'BMW Alpina', 'MINI' => 'MINI', 'SSANGYONG' => 'SsangYong',
        'LEXUS' => 'Lexus', 'JAGUAR' => 'Jaguar',
    ];

    /**
     * Parse a feed XML file into normalised item arrays.
     *
     * @return array|WP_Error
     */
    public static function parse($xml_path) {
        $raw = file_get_contents($xml_path);
        if ($raw === false || $raw === '') {
            return new WP_Error('sm_import_empty_xml', 'Feed XML could not be read or is empty.');
        }

        // The feed declares no encoding, so libxml assumes UTF-8. Motorcentral
        // is a Windows product and will eventually emit a Windows-1252 byte
        // (a macron, a degree sign, a smart quote) that would otherwise fail
        // the entire document.
        if (function_exists('mb_check_encoding')) {
            if (!mb_check_encoding($raw, 'UTF-8')) {
                SM_Import_Log::warn('Feed XML is not valid UTF-8 — converting from Windows-1252.');
                $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            }
        } elseif (!preg_match('//u', $raw)) {
            // mbstring is not universally available on shared hosting.
            SM_Import_Log::warn('Feed XML is not valid UTF-8 — converting from Windows-1252 (mbstring unavailable, using iconv).');
            $converted = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $raw);
            if ($converted !== false) {
                $raw = $converted;
            }
        }

        libxml_use_internal_errors(true);
        libxml_clear_errors();

        // LIBXML_NOENT is deliberately absent: it enables entity substitution
        // and is the XXE foot-gun. LIBXML_NONET blocks external entity fetches.
        $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET | LIBXML_COMPACT);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        foreach ($errors as $error) {
            $message = sprintf('libxml line %d: %s', $error->line, trim($error->message));
            if ($error->level === LIBXML_ERR_FATAL) {
                return new WP_Error('sm_import_xml_fatal', 'Feed XML is malformed. ' . $message);
            }
            SM_Import_Log::warn($message);
        }

        if ($xml === false) {
            return new WP_Error('sm_import_xml_fatal', 'Feed XML could not be parsed.');
        }

        $expected = SM_Import_Settings::get('expected_user_id');
        $user_id  = trim((string) $xml->UserID);
        if ($expected && $user_id !== $expected) {
            return new WP_Error('sm_import_wrong_feed', sprintf(
                'Feed UserID is "%s", expected "%s" — refusing to import the wrong dealer\'s stock.',
                $user_id, $expected
            ));
        }

        // NOTE: stock numbers are numeric strings, and PHP silently casts those
        // to integers when used as array keys. Always cast back with (string)
        // before comparing against a stock number read from the database, or
        // strict comparisons will fail in ways that are painful to spot.
        $items = [];
        foreach ($xml->Item as $node) {
            $item = self::map_item($node);
            if ($item === null) {
                continue;
            }
            if (isset($items[$item['stock_no']])) {
                SM_Import_Log::warn(sprintf(
                    'Feed contains StockNo %s more than once — keeping the first occurrence.', $item['stock_no']
                ));
                continue;
            }
            $items[$item['stock_no']] = $item;
        }

        if (empty($items)) {
            return new WP_Error('sm_import_no_items', 'Feed XML contains no vehicles.');
        }

        return $items;
    }

    /**
     * Map one <Item> onto normalised WordPress-shaped values.
     */
    private static function map_item(SimpleXMLElement $node) {
        $get = function ($key) use ($node) {
            return isset($node->{$key}) ? trim((string) $node->{$key}) : '';
        };

        $stock_no = $get('StockNo');
        if ($stock_no === '') {
            SM_Import_Log::warn('Skipping feed item with no StockNo — it cannot be matched to a vehicle.');
            SM_Import_Log::bump('skipped');
            return null;
        }

        $make        = self::tidy_make($get('Make'));
        $model       = self::tidy_model($get('Model'));
        $variant_raw = $get('Variant');
        $variant     = self::derive_trim($variant_raw);
        $body        = self::map_body($get('Type'), $stock_no);
        $fuel        = self::map_fuel($get('FuelType'), $variant_raw . ' ' . $model, $stock_no);
        $trans       = self::map_transmission($get('Transmission'), $variant_raw, $stock_no);

        $year = absint($get('Year'));
        if ($year < 1900 || $year > (int) gmdate('Y') + 2) {
            if ($get('Year') !== '') {
                SM_Import_Log::warn(sprintf('Implausible Year "%s" (StockNo %s) — discarded.', $get('Year'), $stock_no));
            }
            $year = 0;
        }

        $price = absint($get('Retail'));
        if ($price === 0) {
            SM_Import_Log::warn(sprintf('No retail price (StockNo %s) — vehicle will display as POA.', $stock_no));
        }

        // 0 cc is how the feed represents an electric vehicle. Writing it would
        // render "0 cc" in the spec table.
        $engine = absint($get('CCRating'));
        $doors  = absint($get('Doors'));

        $features = [];
        if (isset($node->Extras->Extra)) {
            foreach ($node->Extras->Extra as $extra) {
                $value = trim((string) $extra);
                if ($value !== '') {
                    $features[] = $value;
                }
            }
        }
        $features = array_values(array_unique($features));
        sort($features);

        $content = self::format_notes($get('OnlineNotes'));
        self::flag_price_mismatch($get('OnlineNotes'), $price, $stock_no);

        // Userdefined1 is 0.00 across the whole sample feed, but the field
        // exists for exactly this purpose — honour it if it is ever populated.
        $weekly = '';
        $ud1    = trim($get('Userdefined1'));
        if (is_numeric($ud1) && (float) $ud1 > 0) {
            $weekly = (string) round((float) $ud1);
        }

        $raw = [];
        foreach ($node->children() as $name => $child) {
            if ($name === 'Extras') {
                continue;
            }
            $raw[$name] = trim((string) $child);
        }
        $raw['Extras'] = $features;

        return [
            'stock_no'    => sanitize_text_field($stock_no),
            'title'       => self::build_title($year, $make, $model, $variant, $body),
            'content'     => $content,
            'features'    => $features,
            'body'        => $body,
            'weekly_feed' => $weekly,
            'raw'         => $raw,
            'meta'        => [
                '_vehicle_stock_no'    => sanitize_text_field($stock_no),
                '_vehicle_make'        => $make,
                '_vehicle_model'       => $model,
                '_vehicle_variant'     => $variant,
                '_vehicle_variant_raw' => $variant_raw,
                '_vehicle_year'        => $year ? (string) $year : '',
                '_vehicle_odometer'    => (string) absint($get('Mileage')),
                '_vehicle_price'       => $price ? (string) $price : '',
                '_vehicle_engine'      => $engine ? (string) $engine : '',
                '_vehicle_transmission'=> $trans,
                '_vehicle_fuel'        => $fuel,
                '_vehicle_body'        => $body,
                '_vehicle_doors'       => $doors ? (string) $doors : '',
                '_vehicle_colour'      => self::tidy_name($get('Colour')),
                '_vehicle_rego'        => strtoupper(preg_replace('/\s+/', '', $get('Rego'))),
                '_vehicle_vin'         => strtoupper($get('VIN')),
                '_vehicle_chassis'     => strtoupper($get('Chassis')),
                '_vehicle_condition'   => self::map_condition($get('NewUsed')),
                '_vehicle_location'    => self::tidy_name($get('Location')),
                '_vehicle_wof_expiry'  => self::map_date($get('WofExpiry')),
                '_vehicle_rego_expiry' => self::map_date($get('RegoExpiry')),
            ],
        ];
    }

    // ─── Value maps ──────────────────────────────────────────────────────────

    /**
     * The feed only ever emits A/M, so a CVT car arrives as "Auto". Where the
     * Variant string names it, prefer that; otherwise the caller preserves an
     * existing manual correction.
     */
    private static function map_transmission($code, $variant, $stock_no) {
        if (preg_match('/\bCVT\b/i', $variant)) {
            return 'CVT';
        }

        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }
        if (isset(self::TRANSMISSION[$code])) {
            return self::TRANSMISSION[$code];
        }

        SM_Import_Log::unmapped('transmission', $code, $stock_no);
        return '';
    }

    /**
     * FuelType is reliable except for "Other", which in the real feed is always
     * a hybrid the DMS could not categorise. The Variant string names the
     * actual drivetrain (E POWER, HYBRID, PHEV), so sniff it.
     */
    private static function map_fuel($value, $haystack, $stock_no) {
        $key = strtoupper(trim($value));
        if ($key === '') {
            return '';
        }

        if (isset(self::FUEL[$key])) {
            return self::FUEL[$key];
        }

        if ($key === 'OTHER' || $key === 'UNKNOWN') {
            // Order matters: plug-in before hybrid, or a PHEV reads as Hybrid.
            if (preg_match('/PHEV|PLUG.?IN/i', $haystack)) {
                return 'Plug-In Hybrid';
            }
            if (preg_match('/HYBRID|\bHEV\b|E.?POWER/i', $haystack)) {
                return 'Hybrid';
            }
            if (preg_match('/ELECTRIC|\bEV\b|\bBEV\b/i', $haystack)) {
                return 'Electric';
            }
        }

        SM_Import_Log::unmapped('fuel type', $value, $stock_no);
        return '';
    }

    private static function map_body($value, $stock_no) {
        $key = strtoupper(trim($value));
        if ($key === '') {
            return '';
        }
        if (isset(self::BODY[$key])) {
            return self::BODY[$key];
        }

        SM_Import_Log::unmapped('body type', $value, $stock_no);
        return '';
    }

    private static function map_condition($value) {
        $key = strtoupper(trim($value));
        if ($key === 'U') return 'Used';
        if ($key === 'N') return 'New';
        return '';
    }

    /** YYYYMMDD => Y-m-d, rejecting impossible dates. */
    private static function map_date($value) {
        $value = trim($value);
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
            return '';
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }
        return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
    }

    // ─── Text derivation ─────────────────────────────────────────────────────

    /**
     * Variant arrives hard-truncated to 32 characters, formatted roughly as
     * "{TRIM} {DOORS} DOOR {CC} {BODY} {TRANS} {FUEL}" — so the trim is
     * whatever precedes the specification tail.
     *
     *   "AWD HYBRID 5 DOOR 2.0 RV-SUV AUT" => "AWD Hybrid"
     *   "5 DOOR E POWER 5 DOOR 1.2 HATCHB" => "E Power"
     *   "3.0 SUPERCHARGED 3.0 CONVERTIBLE" => "3.0 Supercharged"
     *   "5 DOOR 1.6 HATCHBACK AUTO PETROL" => ""
     */
    public static function derive_trim($variant) {
        $variant = trim(preg_replace('/\s+/', ' ', $variant));
        if ($variant === '') {
            return '';
        }

        $trim = null;

        // Cut at the last door count — the tail always restates it.
        if (preg_match_all('/\d+\s*DOOR/i', $variant, $m, PREG_OFFSET_CAPTURE)) {
            $last = end($m[0]);
            $trim = substr($variant, 0, $last[1]);
        } elseif (preg_match_all('/\d\.\d/', $variant, $m, PREG_OFFSET_CAPTURE)) {
            // No door count (convertibles, coupes) — cut at the engine size.
            $last = end($m[0]);
            $trim = substr($variant, 0, $last[1]);
        } elseif (preg_match('/\b(HATCHBACK|SEDAN|RV-SUV|SUV|CONVERTIBLE|WAGON|COUPE|UTE)\b/i', $variant, $m, PREG_OFFSET_CAPTURE)) {
            $trim = substr($variant, 0, $m[0][1]);
        }

        if ($trim === null) {
            return '';
        }

        // A leading door count belongs to the tail template, not the trim.
        $trim = preg_replace('/^\s*\d+\s*DOOR\s*/i', '', $trim);
        $trim = trim(preg_replace('/\s+/', ' ', $trim));

        return self::tidy_name($trim);
    }

    /**
     * Title-case a value that arrives in ALL CAPS, leaving known acronyms and
     * already-mixed-case values alone.
     */
    public static function tidy_name($value) {
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if ($value === '') {
            return '';
        }

        // Respect casing the feed already got right (e.g. "F-TYPE" stays, but
        // so would "Forester").
        if ($value !== strtoupper($value)) {
            return $value;
        }

        $words = explode(' ', strtolower($value));
        foreach ($words as $i => $word) {
            $upper = strtoupper($word);

            if (in_array($upper, self::ACRONYMS, true)) {
                $words[$i] = $upper;
                continue;
            }
            // Alphanumeric mixes like "SR5" or "2.0" keep their shape.
            if (preg_match('/\d/', $word)) {
                $words[$i] = $upper;
                continue;
            }
            // Hyphenated names title-case each part: "i-pace" => "I-Pace".
            $words[$i] = implode('-', array_map('ucfirst', explode('-', $word)));
        }

        return implode(' ', $words);
    }

    /**
     * Model names are badge names, so title-casing them wholesale is wrong:
     * "BEETLE" should become "Beetle", but "F-TYPE", "I-PACE", "XV", "GLA 180"
     * and "A180" are all correct exactly as the feed sends them.
     *
     * A token keeps its capitals when it is short (XV, GLA), contains a digit
     * (A180, RAV4, 180), or is a hyphenated designation whose first part is a
     * single letter (F-TYPE, I-PACE, CX-5).
     */
    public static function tidy_model($value) {
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if ($value === '' || $value !== strtoupper($value)) {
            return $value;
        }

        $words = explode(' ', $value);
        foreach ($words as $i => $word) {
            if (strlen($word) <= 3 || preg_match('/\d/', $word)) {
                continue; // XV, GLA, A180, 180
            }
            if (preg_match('/^[A-Z]-/', $word)) {
                continue; // F-TYPE, I-PACE
            }
            if (in_array($word, self::ACRONYMS, true)) {
                continue;
            }
            $words[$i] = implode('-', array_map('ucfirst', explode('-', strtolower($word))));
        }

        return implode(' ', $words);
    }

    private static function tidy_make($value) {
        $key = strtoupper(trim($value));
        if (isset(self::MAKES[$key])) {
            return self::MAKES[$key];
        }
        return self::tidy_name($value);
    }

    /**
     * "{Year} {Make} {Model} {Trim}", falling back to the body type when the
     * feed carries no usable trim. The raw Variant is never used — it is
     * truncated mid-word and would produce titles like
     * "2020 Nissan Note 5 Door E Power 5 Door 1.2 Hatchb".
     */
    public static function build_title($year, $make, $model, $trim, $body) {
        $parts = array_filter([
            $year ? (string) $year : '',
            $make,
            $model,
            $trim !== '' ? $trim : $body,
        ]);

        return trim(implode(' ', $parts));
    }

    /** OnlineNotes is plain text with blank-line paragraphs. */
    private static function format_notes($notes) {
        $notes = trim(wp_strip_all_tags($notes));
        if ($notes === '') {
            return '';
        }

        $notes      = str_replace(["\r\n", "\r"], "\n", $notes);
        $paragraphs = preg_split('/\n\s*\n/', $notes);

        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph !== '') {
                $html .= '<p>' . str_replace("\n", '<br />', esc_html($paragraph)) . "</p>\n";
            }
        }

        return wp_kses_post(trim($html));
    }

    /**
     * Dealer descriptions are written by hand and go stale. Where the copy
     * quotes a price that is not the feed's retail price, both end up on the
     * same page — a Fair Trading Act exposure, not a cosmetic one. Flag it for
     * review rather than silently editing the dealer's sales copy.
     */
    private static function flag_price_mismatch($notes, $price, $stock_no) {
        if (!$price || $notes === '') {
            return;
        }

        if (!preg_match_all('/\$\s?(\d{4,6})/', $notes, $m)) {
            return;
        }

        foreach ($m[1] as $quoted) {
            if (abs((int) $quoted - $price) > 1) {
                SM_Import_Log::warn(sprintf(
                    'REVIEW: description for StockNo %s quotes $%s but the feed price is $%s — both will appear on the vehicle page.',
                    $stock_no, number_format((int) $quoted), number_format($price)
                ));
                return;
            }
        }
    }
}
