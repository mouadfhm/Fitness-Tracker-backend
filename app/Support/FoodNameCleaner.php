<?php

namespace App\Support;

class FoodNameCleaner
{
    /**
     * USDA Shrt_Desc abbreviation -> expansion. Matched as whole comma-separated
     * segments or whole words, case-insensitively. Extend as new abbreviations
     * turn up in source_name during manual QA of the output.
     */
    protected static array $abbreviations = [
        'W/' => 'with',
        'WO/' => 'without',
        'W/O' => 'without',
        'CRMD' => 'creamed',
        'UNCRMD' => 'uncreamed',
        'NONFAT' => 'nonfat',
        'LOWFAT' => 'low fat',
        'LO FAT' => 'low fat',
        'REG' => 'regular',
        'COND' => 'condensed',
        'EVAP' => 'evaporated',
        'IMIT' => 'imitation',
        'ENR' => 'enriched',
        'UNENR' => 'unenriched',
        'WHL' => 'whole',
        'FROZ' => 'frozen',
        'PDR' => 'powder',
        'GRAN' => 'granular',
        'SWTND' => 'sweetened',
        'UNSWTND' => 'unsweetened',
        'FORT' => 'fortified',
        'VIT' => 'vitamin',
        'NF' => 'nonfat',
        'LO' => 'low',
        'HI' => 'high',
        'UNSALTED' => 'unsalted',
        'SPRD' => 'spread',
        'SUB' => 'substitute',
        'SUBSTITUTES' => 'substitute',
        'DRSNG' => 'dressing',
        'CRM' => 'cream',
        'LN' => 'lean',
        'RSTD' => 'roasted',
        'BLCH' => 'blanched',
        'CKD' => 'cooked',
        'BLD' => 'boiled',
        'BRLD' => 'broiled',
        'STMD' => 'steamed',
        'BRSD' => 'braised',
        'STWD' => 'stewed',
        'CND' => 'canned',
        'FRZ' => 'frozen',
        'VEG' => 'vegetable',
        'JUC' => 'juice',
        'CONC' => 'concentrate',
        'WHT' => 'wheat',
        'GRNDNUT' => 'groundnut',
        'LRG' => 'large',
        'SML' => 'small',
        'MED' => 'medium',
        'PC' => 'piece',
        'PCS' => 'pieces',
        'SLC' => 'slice',
        'SLCD' => 'sliced',
        'CHPD' => 'chopped',
        'DICED' => 'diced',
        'GRTD' => 'grated',
        'SHRDDED' => 'shredded',
        'MASHED' => 'mashed',
        'PUREED' => 'pureed',
        'RTD' => 'ready-to-drink',
        'RTS' => 'ready-to-serve',
        'RTE' => 'ready-to-eat',
        'FD' => 'food',
        'BEV' => 'beverage',
        'BEVERAGES' => 'beverage',
        'MFG' => 'manufactured',
        'ORIG' => 'original',
        'DIET' => 'diet',
        'ALL PURP' => 'all purpose',
        // Abbreviations still left in names after the first pass.
        'BABYFOOD' => 'baby food',
        'STR' => 'strained',
        'JR' => 'junior',
        'PNAPPL' => 'pineapple',
        'APPL' => 'apple',
        'APPLS' => 'apples',
        'H2O' => 'water',
        'NA' => 'sodium',
        'DRND' => 'drained',
        'RND' => 'round',
        'FRSH' => 'fresh',
        'IMP' => 'imported',
        'RST' => 'roast',
        'SKN' => 'skin',
        'GRDS' => 'grades',
        'ALLGRD' => 'all grades',
        'BF' => 'beef',
        'CHS' => 'cheese',
        'NZ' => 'New Zealand',
        'AUS' => 'Australian',
        'SHLDR' => 'shoulder',
        'SHDR' => 'shoulder',
        'STK' => 'steak',
        'STKS' => 'steaks',
        'INF' => 'infant',
        'PLN' => 'plain',
        'INST' => 'instant',
        'SWT' => 'sweet',
        'SNDWCH' => 'sandwich',
        'MXD' => 'mixed',
        'BKD' => 'baked',
        'BNS' => 'beans',
        'FLR' => 'flour',
        'GRN' => 'green',
        'GRNS' => 'greens',
        'CRL' => 'cereal',
        'RTL' => 'retail',
        'BNLS' => 'boneless',
        'BNLES' => 'boneless',
        'BNLESS' => 'boneless',
        'BNLSS' => 'boneless',
        'CNTR' => 'center',
        'DK' => 'dark',
        'HTD' => 'heated',
        'UNHTD' => 'unheated',
        'INCL' => 'including',
        'SHRT' => 'short',
        'FRSTD' => 'frosted',
        'FRSTNG' => 'frosting',
        'BTTM' => 'bottom',
        'CHK' => 'chuck',
        'CHCK' => 'chuck',
        'RTF' => 'ready-to-feed',
        'RTH' => 'ready-to-heat',
        'GRLD' => 'grilled',
        'GRLLD' => 'grilled',
        'BTLD' => 'bottled',
        'TSTD' => 'toasted',
        'DSSRT' => 'dessert',
        'BRKFST' => 'breakfast',
        'KRNLS' => 'kernels',
        'RW' => 'raw',
        'SMMR' => 'summer',
        'WNTR' => 'winter',
        'UNCKD' => 'uncooked',
        'MSHD' => 'mashed',
        'PRTRHS' => 'porterhouse',
        'CHC' => 'choice',
        'CHOIC' => 'choice',
        'ALL GRD' => 'all grades',
        'CHOC' => 'chocolate',
        'SEL' => 'select',
        'VAR' => 'variety',
        'PROT' => 'protein',
        'SHK' => 'shank',
        'BRD' => 'bread',
        'SWTNR' => 'sweetener',
        'CTTNSD' => 'cottonseed',
        'ASPRT' => 'aspartame',
        'GRSS' => 'grass',
        'LTTC' => 'lettuce',
        'SKRT' => 'skirt',
        'BRST' => 'breast',
        'TMT' => 'tomato',
        'CHPS' => 'chips',
        'BRWN' => 'brown',
        'SGR' => 'sugar',
        'FRT' => 'fruit',
        'STD' => 'standard',
        'SNK' => 'snack',
        'CKN' => 'chicken',
        'YNG' => 'young',
        'TNDR' => 'tender',
        'SKNLS' => 'skinless',
        'XCPT' => 'except',
        'UNPRP' => 'unprepared',
        'UNPREP' => 'unprepared',
        'TSTR' => 'toaster',
        'PSTRS' => 'pastries',
        'RMVD' => 'removed',
        'SRLN' => 'sirloin',
        'BRDNG' => 'breading',
        'PREP' => 'prepared',
        'DEHYD' => 'dehydrated',
        'DRK' => 'drink',
        'PK' => 'pack',
        'SD' => 'seed',
        'LT' => 'light',
        'EQ' => 'equal',
        'UND' => 'under',
        'SLN' => 'solution',
        'WH' => 'white',
        'MT' => 'meat',
        'FT' => 'fat',
        'BRLER' => 'broiler',
        'YEL' => 'yellow',
        'DUCKL' => 'duckling',
        'DOM' => 'domesticated',
        'SOL' => 'solids',
        'TENLN' => 'tenderloin',
        'TDLN' => 'tenderloin',
        'SCR' => 'score',
        'MRB' => 'marbling',
        'WGU' => 'wagyu',
    ];

    /**
     * Turn a raw USDA Shrt_Desc like "CHEESE,COTTAGE,CRMD,LRG OR SML CURD"
     * into "Cottage Cheese, Creamed (Large or Small Curd)".
     */
    public static function clean(string $rawShortDesc): string
    {
        $segments = array_map('trim', explode(',', trim($rawShortDesc, " \t\n\r\0\x0B\"'")));
        $segments = array_values(array_filter($segments, fn ($s) => $s !== ''));

        if (empty($segments)) {
            return '';
        }

        $segments = array_map([self::class, 'expandSegment'], $segments);

        // USDA convention: first segment is the base food, second is often a
        // type-modifier that reads more naturally in front of it
        // (e.g. "CHEESE,COTTAGE" -> "Cottage Cheese").
        $base = array_shift($segments);
        $lead = '';

        if (!empty($segments) && self::looksLikeLeadingModifier($segments[0])) {
            $lead = array_shift($segments);
        }

        $name = $lead !== '' ? "{$lead} {$base}" : $base;
        $name = self::titleCase($name);

        if (!empty($segments)) {
            $qualifiers = implode(', ', array_map([self::class, 'titleCase'], $segments));
            $name .= " ({$qualifiers})";
        }

        return $name;
    }

    protected static function expandSegment(string $segment): string
    {
        $upper = strtoupper($segment);

        if (isset(self::$abbreviations[$upper])) {
            return self::$abbreviations[$upper];
        }

        // Handle "W/FRUIT", "WO/SALT" etc. where the abbreviation is glued to
        // the next word with no space.
        $segment = preg_replace('/\bW\/(?=\S)/i', 'with ', $segment);
        $segment = preg_replace('/\bWO\/(?=\S)/i', 'without ', $segment);

        // "BANANAS&PNAPPL" -> "BANANAS & PNAPPL" so both words get expanded.
        $segment = preg_replace('/\s*&\s*/', ' & ', $segment);

        $words = preg_split('/\s+/', $segment);
        $words = array_map(function ($word) {
            $exact = strtoupper($word);
            if (isset(self::$abbreviations[$exact])) {
                return self::$abbreviations[$exact];
            }

            $trimmed = strtoupper(trim($word, '/'));

            return self::$abbreviations[$trimmed] ?? $word;
        }, $words);

        return implode(' ', $words);
    }

    /**
     * Heuristic: a short (<=2 word) alphabetic segment right after the base
     * food is usually a type/variety, e.g. COTTAGE, CHEDDAR, SKIM — reads
     * better moved in front of the base ("Cottage Cheese") than appended.
     * Segments that are actually prepositional qualifiers (with/without/from)
     * read better left where they are, e.g. "Butter (with Salt)".
     */
    protected static function looksLikeLeadingModifier(string $segment): bool
    {
        $wordCount = str_word_count($segment);
        $firstWord = strtolower(strtok($segment, ' ') ?: '');

        if (in_array($firstWord, ['with', 'without', 'from', 'in', 'and'], true)) {
            return false;
        }

        return $wordCount > 0 && $wordCount <= 2 && ctype_alpha(str_replace(' ', '', $segment));
    }

    protected static function titleCase(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/\s+/', ' ', trim($text));

        return ucwords($text);
    }
}
