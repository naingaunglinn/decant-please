<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $brandName => $brand) {
            $brandModel = Brand::create([
                'name' => $brandName,
                'type' => $brand['type'],
            ]);

            foreach ($brand['fragrances'] as $fragrance) {
                $prices = $fragrance['prices'];
                unset($fragrance['prices']);

                $fragranceModel = $brandModel->fragrances()->create($fragrance);

                foreach ($prices as $size => $price) {
                    $fragranceModel->decantPrices()->create([
                        'size_ml' => $size,
                        'price_mmk' => $price,
                    ]);
                }
            }
        }
    }

    /**
     * @return array<string, array{type: string, fragrances: array<int, array<string, mixed>>}>
     */
    private function catalog(): array
    {
        return [
            'Chanel' => ['type' => 'designer', 'fragrances' => [
                [
                    'name' => 'Allure Homme Sport',
                    'concentration' => 'cologne',
                    'gender' => 'male',
                    'notes' => 'Orange, Grapefruit, Sea Notes, Cedar, Musk',
                    'vibes' => 'Fresh, Sporty, Clean, Versatile',
                    'performance' => 'Around 4-6 Hours',
                    'description' => 'A crisp citrus-marine cologne that works anywhere — the definition of an easy, refreshing signature.',
                    'is_featured' => true,
                    'prices' => [5 => 30000, 10 => 55000, 30 => 150000],
                ],
                [
                    'name' => 'Bleu de Chanel',
                    'concentration' => 'edp',
                    'gender' => 'male',
                    'notes' => 'Citrus, Incense, Ginger, Sandalwood',
                    'vibes' => 'Modern, Classy, Confident',
                    'performance' => 'Around 8-10 Hours',
                    'description' => 'The modern gentleman in a bottle — polished woody-aromatic that fits office and evening alike.',
                    'prices' => [5 => 38000, 10 => 70000, 30 => 190000],
                ],
                [
                    'name' => 'Coco Mademoiselle',
                    'concentration' => 'edp',
                    'gender' => 'female',
                    'notes' => 'Orange, Rose, Jasmine, Patchouli, Vanilla',
                    'vibes' => 'Elegant, Feminine, Timeless',
                    'performance' => 'Around 8 Hours',
                    'description' => 'A sparkling oriental-fresh classic — graceful by day, quietly seductive by night.',
                    'prices' => [5 => 40000, 10 => 75000, 30 => 200000],
                ],
            ]],
            'Dior' => ['type' => 'designer', 'fragrances' => [
                [
                    'name' => 'Sauvage',
                    'concentration' => 'edt',
                    'gender' => 'male',
                    'notes' => 'Bergamot, Pepper, Ambroxan, Vanilla',
                    'vibes' => 'Bold, Fresh, Crowd-Pleasing',
                    'performance' => 'Around 8-10 Hours',
                    'description' => 'The most complimented masculine of its generation — radiant bergamot over rugged ambroxan.',
                    'is_featured' => true,
                    'prices' => [5 => 35000, 10 => 65000, 30 => 175000],
                ],
                [
                    'name' => 'Dior Homme Intense',
                    'concentration' => 'edp',
                    'gender' => 'male',
                    'notes' => 'Iris, Lavender, Pear, Vetiver',
                    'vibes' => 'Sophisticated, Romantic, Night Out',
                    'performance' => 'Around 8 Hours',
                    'description' => 'Velvety iris and lavender — a smooth, intimate scent for evenings and cooler weather.',
                    'prices' => [5 => 42000, 10 => 78000, 30 => 210000],
                ],
                [
                    'name' => 'Miss Dior',
                    'concentration' => 'edp',
                    'gender' => 'female',
                    'notes' => 'Iris, Peony, Rose, Musk',
                    'vibes' => 'Romantic, Fresh, Charming',
                    'performance' => 'Around 6-8 Hours',
                    'description' => 'A bouquet of peony and rose wrapped in soft musk — romance in wearable form.',
                    'prices' => [5 => 38000, 10 => 72000],
                ],
            ]],
            'Versace' => ['type' => 'designer', 'fragrances' => [
                [
                    'name' => 'Eros',
                    'concentration' => 'edt',
                    'gender' => 'male',
                    'notes' => 'Mint, Green Apple, Tonka Bean, Vanilla',
                    'vibes' => 'Sweet, Seductive, Youthful',
                    'performance' => 'Around 8 Hours',
                    'description' => 'Mint and green apple over a sweet tonka base — loud, fun, and made for nights out.',
                    'prices' => [5 => 28000, 10 => 50000, 30 => 135000],
                ],
                [
                    'name' => 'Dylan Blue',
                    'concentration' => 'edt',
                    'gender' => 'male',
                    'notes' => 'Bergamot, Grapefruit, Incense, Musk',
                    'vibes' => 'Fresh, Masculine, Everyday',
                    'performance' => 'Around 6-8 Hours',
                    'description' => 'An aquatic-woody everyday scent with a subtle incense twist.',
                    'prices' => [5 => 26000, 10 => 48000],
                ],
            ]],
            'Yves Saint Laurent' => ['type' => 'designer', 'fragrances' => [
                [
                    'name' => 'Y',
                    'concentration' => 'edp',
                    'gender' => 'male',
                    'notes' => 'Apple, Ginger, Sage, Amberwood',
                    'vibes' => 'Modern, Clean, Ambitious',
                    'performance' => 'Around 7-9 Hours',
                    'description' => 'Crisp apple and sage sharpened by amberwood — clean-cut and quietly ambitious.',
                    'prices' => [5 => 34000, 10 => 62000, 30 => 165000],
                ],
                [
                    'name' => 'Libre',
                    'concentration' => 'edp',
                    'gender' => 'female',
                    'notes' => 'Lavender, Orange Blossom, Vanilla',
                    'vibes' => 'Bold, Elegant, Free-Spirited',
                    'performance' => 'Around 8 Hours',
                    'description' => 'Lavender made glamorous — a bold floral with a warm vanilla trail.',
                    'prices' => [5 => 36000, 10 => 68000],
                ],
                [
                    'name' => "La Nuit de L'Homme",
                    'concentration' => 'edt',
                    'gender' => 'male',
                    'notes' => 'Cardamom, Lavender, Cedar, Cumin',
                    'vibes' => 'Seductive, Date Night, Smooth',
                    'performance' => 'Around 5-7 Hours',
                    'description' => 'Spicy cardamom over soft woods — the quiet date-night legend.',
                    'prices' => [5 => 30000, 10 => 56000],
                ],
                [
                    'name' => 'MYSLF',
                    'concentration' => 'edp',
                    'gender' => 'male',
                    'notes' => 'Bergamot, Orange Blossom, Woods',
                    'vibes' => 'Fresh, Refined, Modern',
                    'performance' => 'Around 6-8 Hours',
                    'description' => 'A modern floral-woody masculine — orange blossom worn with a suit.',
                    'prices' => [5 => 36000, 10 => 66000],
                ],
            ]],
            'Jean Paul Gaultier' => ['type' => 'designer', 'fragrances' => [
                [
                    'name' => 'Le Male Elixir',
                    'concentration' => 'parfum',
                    'gender' => 'male',
                    'notes' => 'Lavender, Mint, Vanilla, Honey, Tobacco',
                    'vibes' => 'Sweet, Warm, Head-Turning',
                    'performance' => 'Around 10-12 Hours',
                    'description' => 'Honeyed lavender and tobacco that lasts all night — dense, sweet, unmissable.',
                    'is_featured' => true,
                    'prices' => [5 => 40000, 10 => 75000, 30 => 200000],
                ],
                [
                    'name' => 'Scandal',
                    'concentration' => 'edp',
                    'gender' => 'female',
                    'notes' => 'Blood Orange, Honey, Gardenia, Beeswax',
                    'vibes' => 'Sweet, Playful, Addictive',
                    'performance' => 'Around 7-9 Hours',
                    'description' => 'Honey and blood orange in a playful gourmand — dangerously easy to overspray.',
                    'prices' => [5 => 36000, 10 => 67000],
                ],
            ]],
            'Creed' => ['type' => 'niche', 'fragrances' => [
                [
                    'name' => 'Aventus',
                    'concentration' => 'edp',
                    'gender' => 'male',
                    'notes' => 'Pineapple, Blackcurrant, Birch, Musk',
                    'vibes' => 'Confident, Luxurious, Signature-Worthy',
                    'performance' => 'Around 8-10 Hours',
                    'description' => 'Smoky pineapple over birch — the benchmark niche masculine, made for winners.',
                    'is_featured' => true,
                    'prices' => [5 => 65000, 10 => 120000, 30 => 330000],
                ],
                [
                    'name' => 'Green Irish Tweed',
                    'concentration' => 'edp',
                    'gender' => 'male',
                    'notes' => 'Lemon Verbena, Violet Leaf, Sandalwood',
                    'vibes' => 'Classic, Fresh, Gentlemanly',
                    'performance' => 'Around 7-9 Hours',
                    'description' => 'A timeless green fougère — fresh-cut grass and violet leaf, endlessly refined.',
                    'is_active' => false,
                    'prices' => [5 => 60000, 10 => 110000],
                ],
            ]],
            'Parfums de Marly' => ['type' => 'niche', 'fragrances' => [
                [
                    'name' => 'Layton',
                    'concentration' => 'edp',
                    'gender' => 'male',
                    'notes' => 'Apple, Lavender, Geranium, Vanilla, Pepper',
                    'vibes' => 'Elegant, Warm, Compliment Magnet',
                    'performance' => 'Around 9-11 Hours',
                    'description' => 'Crisp apple over warm vanilla and spice — royal polish with serious presence.',
                    'prices' => [5 => 55000, 10 => 100000, 30 => 270000],
                ],
                [
                    'name' => 'Delina',
                    'concentration' => 'edp',
                    'gender' => 'female',
                    'notes' => 'Lychee, Rose, Peony, Vanilla',
                    'vibes' => 'Feminine, Luxurious, Radiant',
                    'performance' => 'Around 8-10 Hours',
                    'description' => 'Lychee-drenched Turkish rose — plush, radiant, and unmistakably luxurious.',
                    'prices' => [5 => 58000, 10 => 105000],
                ],
            ]],
            'Maison Francis Kurkdjian' => ['type' => 'niche', 'fragrances' => [
                [
                    'name' => 'Baccarat Rouge 540',
                    'concentration' => 'edp',
                    'gender' => 'unisex',
                    'notes' => 'Saffron, Jasmine, Amberwood, Fir Resin',
                    'vibes' => 'Iconic, Sweet, Long-Lasting',
                    'performance' => 'Around 10-12 Hours',
                    'description' => 'The airy saffron-amber phenomenon — sweet, mineral, and recognized everywhere.',
                    'is_featured' => true,
                    'prices' => [5 => 70000, 10 => 130000, 30 => 350000],
                ],
                [
                    'name' => 'Grand Soir',
                    'concentration' => 'edp',
                    'gender' => 'unisex',
                    'notes' => 'Amber, Vanilla, Tonka Bean, Benzoin',
                    'vibes' => 'Warm, Cozy, Evening',
                    'performance' => 'Around 9-11 Hours',
                    'description' => 'Golden amber and vanilla for the grand evening — a cashmere blanket of a scent.',
                    'prices' => [5 => 62000, 10 => 115000],
                ],
            ]],
        ];
    }
}
