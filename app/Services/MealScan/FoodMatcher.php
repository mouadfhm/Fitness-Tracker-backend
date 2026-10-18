<?php

namespace App\Services\MealScan;

use App\Models\Food;

/**
 * Finds the food in the foods table that best fits a name the camera gave,
 * so a scanned meal is logged with the same macros as one typed in by hand.
 */
class FoodMatcher
{
    /**
     * Words that say how a food is served rather than what it is. They don't
     * count against a candidate ("Oranges, raw" is as plain as "Orange"), and
     * when nothing has them the search tries again without them.
     */
    private const PREPARATION = [
        'raw', 'cooked', 'boiled', 'grilled', 'fried', 'baked', 'roasted',
        'steamed', 'fresh', 'sliced', 'chopped', 'plain', 'whole', 'prepared',
        'peeled',
    ];

    /**
     * Words that turn a food into something made from it ("Salmon oil",
     * "Lemon peel", "Orange soda"). A camera sees the food itself far more
     * often, so these count as several extra words.
     */
    private const DERIVED = [
        'oil', 'juice', 'powder', 'sauce', 'mix', 'pie', 'flour', 'soda',
        'peel', 'drink', 'candy', 'syrup', 'extract', 'liver', 'pudding',
        'gravy', 'soup', 'chips', 'dried', 'canned', 'frozen', 'babyfood',
    ];

    /** Candidates scored per phrase; the plainest names are the shortest. */
    private const CANDIDATES = 300;

    /**
     * @param  list<string>  $terms  tried in order, most specific first, e.g.
     *                               "Chicken breast, grilled" then "chicken breast"
     */
    public function match(array $terms, ?int $userId): ?Food
    {
        $tried = [];
        foreach ($terms as $term) {
            $words = $this->words($term);
            $core = array_values(array_diff($words, self::PREPARATION));
            foreach ([$words, $core] as $phrase) {
                $key = implode(' ', $phrase);
                if ($phrase === [] || isset($tried[$key])) {
                    continue;
                }
                $tried[$key] = true;
                if ($food = $this->find($phrase, $userId)) {
                    return $food;
                }
            }
        }

        return null;
    }

    /**
     * The food whose English name has every word, as a whole word or its
     * plural (so "egg" finds "Eggs" but not "Eggnog"), with the fewest other
     * words.
     *
     * @param  list<string>  $words
     */
    private function find(array $words, ?int $userId): ?Food
    {
        $query = Food::query()
            ->where(fn ($q) => $q->whereNull('added_by')->orWhere('added_by', $userId))
            ->whereNotNull('name_en');
        foreach ($words as $word) {
            $query->where('name_en', 'like', '%'.addcslashes($word, '%_\\').'%');
        }
        $candidates = $query->orderByRaw('CHAR_LENGTH(name_en)')->orderBy('id')
            ->limit(self::CANDIDATES)->get();

        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($candidates as $food) {
            $score = $this->score($words, $this->words($food->name_en));
            if ($score !== null && $score < $bestScore) {
                $best = $food;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Lower is better; null when a query word isn't one of the name's words.
     *
     * @param  list<string>  $query
     * @param  list<string>  $name
     */
    private function score(array $query, array $name): ?int
    {
        $used = [];
        foreach ($query as $q) {
            $hit = null;
            foreach ($name as $i => $w) {
                if (! isset($used[$i]) && $this->sameWord($q, $w)) {
                    $hit = $i;
                    break;
                }
            }
            if ($hit === null) {
                return null;
            }
            $used[$hit] = true;
        }

        $extra = 0;
        foreach ($name as $i => $w) {
            if (isset($used[$i]) || in_array($w, self::PREPARATION, true)) {
                continue;
            }
            $extra += in_array($w, self::DERIVED, true) ? 3 : 1;
        }

        // Fewer other words first; then names led by the food itself
        // ("Banana, raw" before "Raw banana chips").
        return $extra * 10 + (isset($used[0]) ? 0 : 1);
    }

    /** "egg" matches "eggs", "berry" matches "berries", "lemon" not "lemongrass". */
    private function sameWord(string $query, string $word): bool
    {
        return $word === $query
            || $word === $query.'s'
            || $word === $query.'es'
            || (str_ends_with($query, 'y') && $word === substr($query, 0, -1).'ies')
            || $query === $word.'s';
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($text), $m);

        return array_values(array_unique($m[0]));
    }
}
