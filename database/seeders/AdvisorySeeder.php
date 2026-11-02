<?php

namespace Database\Seeders;

use App\Models\Advisory;
use Illuminate\Database\Seeder;

class AdvisorySeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['How to take a photo the AI can read', 'Scanning', 'Good photos make better results. Learn the simple habits that matter most.',
                "Shoot in natural daylight, early morning or late afternoon, and avoid harsh midday shadows.\n\nFill the frame with the affected leaf, fruit or stem. Keep the camera steady so the image is sharp.\n\nPhotograph one plant part at a time and include both healthy and affected tissue so differences are visible.\n\nTake a second photo from the underside of the leaf if you see spots or growth there."],
            ['Field hygiene: the cheapest disease control', 'Prevention', 'Clean tools, clean debris and clean hands prevent many outbreaks.',
                "Remove and dispose of infected plant material instead of leaving it in the field.\n\nClean tools and stakes between plots, especially after working on plants you suspect are diseased.\n\nAvoid working among wet plants, because many diseases spread on wet leaves and hands.\n\nRotate crops so the same disease does not build up year after year."],
            ['Watering without wetting the leaves', 'Water management', 'Leaf wetness is the main driver of many fungal and bacterial diseases.',
                "Water at the base of plants, in the morning, so foliage dries quickly.\n\nSpace plants and prune where appropriate so air can move through the canopy.\n\nMulch to stop soil from splashing onto leaves during rain or irrigation."],
            ['When to ask an expert', 'Guidance', 'AI results are a starting point, not a diagnosis.',
                "If a disease is spreading quickly, affects many plants, or you are unsure of the result, contact your local agricultural extension office.\n\nThey can confirm the problem and advise on approved products and rates for your crop and area. Always follow the product label."],
        ];
        foreach ($items as [$title, $cat, $summary, $body]) {
            Advisory::updateOrCreate(['title' => $title], ['category' => $cat, 'summary' => $summary, 'body' => $body, 'is_published' => true, 'published_at' => now()]);
        }
    }
}
