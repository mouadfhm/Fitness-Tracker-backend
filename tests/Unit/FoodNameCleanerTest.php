<?php

namespace Tests\Unit;

use App\Support\FoodNameCleaner;
use PHPUnit\Framework\TestCase;

class FoodNameCleanerTest extends TestCase
{
    public function test_expands_baby_food_abbreviations_and_glued_ampersands(): void
    {
        $this->assertSame(
            'Fruit Baby Food (Bananas & Pineapple, Strained)',
            FoodNameCleaner::clean('BABYFOOD,FRUIT,BANANAS&PNAPPL,STR')
        );
    }

    public function test_expands_meat_cut_abbreviations(): void
    {
        $this->assertSame(
            'Cured Pork (Ham -- Water Added, Whole, Boneless, Lean & Fat, Unheated)',
            FoodNameCleaner::clean('PORK,CURED,HAM -- H2O ADDED,WHL,BNLESS,LN & FAT,UNHTD')
        );
    }

    public function test_low_na_reads_as_low_sodium(): void
    {
        $this->assertSame(
            'Tomato Soup (Low Sodium, With Water)',
            FoodNameCleaner::clean('SOUP,TOMATO,LO NA,W/ H2O')
        );
    }
}
