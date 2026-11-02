<?php

namespace Database\Seeders;

use App\Models\Crop;
use App\Models\Disease;
use Illuminate\Database\Seeder;

/**
 * Starter reference library: widely documented symptoms and cultural/preventive practices ONLY.
 * No pesticide products or application rates are included on purpose. Chemical options and rates depend on
 * local registration and the product label, so administrators should add them only from a verified source
 * (e.g. the local agricultural extension office).
 */
class ReferenceSeeder extends Seeder
{
    private const FOLLOW_UP = 'Re-scan the same plants in 5–7 days, compare with this photo and note whether lesions are spreading to new leaves.';

    private const CHEMICAL_NOTE = 'If you are considering a registered fungicide or bactericide, ask your local agricultural extension office which product is approved for this crop and follow the label exactly.';

    public function run(): void
    {
        foreach ($this->data() as $cropName => $meta) {
            $crop = Crop::updateOrCreate(['name' => $cropName], ['scientific_name' => $meta['sci'], 'description' => $meta['desc']]);
            foreach ($meta['diseases'] as $d) {
                $disease = Disease::updateOrCreate(
                    ['crop_id' => $crop->id, 'slug' => \Illuminate\Support\Str::slug($d['name'])],
                    ['name' => $d['name'], 'description' => $d['desc'], 'severity' => $d['severity'], 'follow_up' => self::FOLLOW_UP],
                );
                $disease->syncReference($d['symptoms'], $d['factors'], array_merge($d['treat'], [self::CHEMICAL_NOTE]), $d['prevent']);
            }
        }
    }

    private function data(): array
    {
        return [
            'Tomato' => [
                'sci' => 'Solanum lycopersicum', 'desc' => 'Warm-season fruiting vegetable.',
                'diseases' => [
                    ['name' => 'Early blight', 'severity' => 'moderate', 'desc' => 'A fungal leaf disease (Alternaria) that starts on older leaves.',
                        'symptoms' => ['Brown spots with concentric rings (a "target" look) on older leaves', 'Yellowing around the spots', 'Dark, sunken lesions near the fruit stem'],
                        'factors' => ['Warm, humid weather and frequent leaf wetness', 'Plant stress or low nitrogen', 'Infected debris left from a previous crop'],
                        'treat' => ['Remove and bag badly affected lower leaves', 'Water at the base of the plant, not over the leaves', 'Improve airflow by staking and spacing plants'],
                        'prevent' => ['Rotate away from tomato, potato and eggplant for 2–3 seasons', 'Clear crop residue after harvest', 'Mulch to reduce soil splash onto leaves']],
                    ['name' => 'Late blight', 'severity' => 'high', 'desc' => 'A fast-moving disease (Phytophthora infestans) favoured by cool, wet conditions.',
                        'symptoms' => ['Large, water-soaked, greasy-looking patches on leaves that turn brown', 'White fuzzy growth on the underside in humid conditions', 'Firm, brown blotches on fruit'],
                        'factors' => ['Cool nights with prolonged leaf wetness or fog', 'Nearby infected tomato or potato plants'],
                        'treat' => ['Act quickly: remove and bag infected plants or plant parts (do not compost)', 'Avoid working in the field while foliage is wet'],
                        'prevent' => ['Use healthy seedlings and avoid overhead irrigation', 'Destroy volunteer potato and tomato plants', 'Scout fields often in cool, wet spells']],
                    ['name' => 'Leaf mold', 'severity' => 'low', 'desc' => 'A fungal disease common in humid greenhouses and dense plantings.',
                        'symptoms' => ['Pale green or yellow spots on the upper leaf surface', 'Olive-green to grey-brown velvety growth on the underside'],
                        'factors' => ['High humidity and poor ventilation'],
                        'treat' => ['Increase ventilation and reduce humidity', 'Remove heavily affected leaves'],
                        'prevent' => ['Space plants for airflow', 'Avoid wetting foliage', 'Sanitise stakes and tools between seasons']],
                    ['name' => 'Septoria leaf spot', 'severity' => 'moderate', 'desc' => 'A fungal disease causing many small leaf spots.',
                        'symptoms' => ['Many small, round spots with grey-white centres and dark edges', 'Tiny dark dots (fruiting bodies) in the spot centres', 'Lower leaves yellow and drop first'],
                        'factors' => ['Splashing rain or irrigation', 'Warm, wet weather'],
                        'treat' => ['Remove infected lower leaves', 'Keep foliage dry and mulch the soil'],
                        'prevent' => ['Rotate crops', 'Remove plant debris at season end']],
                    ['name' => 'Bacterial spot', 'severity' => 'moderate', 'desc' => 'A bacterial disease spread by splashing water and contaminated seed.',
                        'symptoms' => ['Small, dark, greasy-looking spots on leaves, sometimes with a yellow halo', 'Raised, scabby spots on fruit'],
                        'factors' => ['Warm, wet, windy weather', 'Contaminated seed or transplants', 'Working among wet plants'],
                        'treat' => ['Remove severely affected plants', 'Avoid handling plants when they are wet'],
                        'prevent' => ['Use certified disease-free seed or seedlings', 'Rotate crops and clean tools']],
                ],
            ],
            'Potato' => [
                'sci' => 'Solanum tuberosum', 'desc' => 'Cool-season tuber crop.',
                'diseases' => [
                    ['name' => 'Early blight', 'severity' => 'moderate', 'desc' => 'A fungal leaf disease that affects stressed or ageing plants.',
                        'symptoms' => ['Dark brown spots with concentric rings on older leaves', 'Yellowing of tissue around the spots'],
                        'factors' => ['Warm days with alternating wet and dry periods', 'Nutrient-stressed plants'],
                        'treat' => ['Remove infected leaves where practical', 'Maintain balanced fertility and adequate water'],
                        'prevent' => ['Rotate crops', 'Plant certified seed tubers', 'Remove volunteer plants']],
                    ['name' => 'Late blight', 'severity' => 'high', 'desc' => 'A destructive disease that can destroy a field within days in cool, wet weather.',
                        'symptoms' => ['Water-soaked, dark patches on leaves and stems', 'White growth on leaf undersides in humid weather', 'Brown, dry rot in tubers'],
                        'factors' => ['Cool, wet and humid conditions', 'Infected seed tubers or cull piles'],
                        'treat' => ['Remove and destroy infected plants promptly', 'Delay harvest after vine death so tubers cure, and do not store visibly affected tubers'],
                        'prevent' => ['Use healthy certified seed', 'Destroy cull piles and volunteers', 'Hill soil well to protect tubers']],
                ],
            ],
            'Corn' => [
                'sci' => 'Zea mays', 'desc' => 'Maize / corn.',
                'diseases' => [
                    ['name' => 'Northern leaf blight', 'severity' => 'moderate', 'desc' => 'A fungal disease producing long lesions on leaves.',
                        'symptoms' => ['Long, cigar-shaped grey-green to tan lesions on leaves', 'Lesions can merge and blight large areas of leaf'],
                        'factors' => ['Moderate temperatures with heavy dew or rain', 'Corn residue left on the field'],
                        'treat' => ['Manage residue after harvest', 'Consult local extension about resistant varieties for next season'],
                        'prevent' => ['Plant resistant varieties', 'Rotate with non-host crops', 'Till or manage residue']],
                    ['name' => 'Common rust', 'severity' => 'low', 'desc' => 'A fungal disease that forms rust-coloured pustules.',
                        'symptoms' => ['Small, round to elongated, brick-red to brown pustules on both leaf surfaces', 'Pustules release a powdery rust-coloured dust'],
                        'factors' => ['Cool, humid weather'],
                        'treat' => ['Monitor spread; mild cases often need no action'],
                        'prevent' => ['Plant resistant hybrids', 'Avoid very late planting']],
                    ['name' => 'Gray leaf spot', 'severity' => 'moderate', 'desc' => 'A fungal disease causing rectangular leaf lesions.',
                        'symptoms' => ['Narrow, rectangular tan to grey lesions running parallel to the leaf veins'],
                        'factors' => ['Warm, humid weather and corn-after-corn planting'],
                        'treat' => ['Manage crop residue after harvest'],
                        'prevent' => ['Rotate crops', 'Choose tolerant hybrids']],
                ],
            ],
            'Rice' => [
                'sci' => 'Oryza sativa', 'desc' => 'Staple cereal grown in paddy or upland systems.',
                'diseases' => [
                    ['name' => 'Rice blast', 'severity' => 'high', 'desc' => 'A fungal disease (Magnaporthe oryzae) that can affect leaves, nodes and panicles.',
                        'symptoms' => ['Diamond-shaped lesions with grey centres and brown margins on leaves', 'Broken or blackened nodes and neck, causing empty panicles'],
                        'factors' => ['Excess nitrogen fertiliser', 'Long periods of leaf wetness and cool nights', 'Susceptible varieties'],
                        'treat' => ['Avoid excess nitrogen and split applications', 'Maintain proper water management'],
                        'prevent' => ['Plant resistant varieties recommended locally', 'Use clean seed', 'Remove infected straw and stubble']],
                    ['name' => 'Brown spot', 'severity' => 'moderate', 'desc' => 'A fungal disease linked to poor soil fertility and stress.',
                        'symptoms' => ['Oval brown spots with a yellow halo on leaves', 'Dark spots on grains'],
                        'factors' => ['Nutrient-deficient soil', 'Drought stress'],
                        'treat' => ['Correct soil nutrient deficiencies', 'Keep fields adequately watered'],
                        'prevent' => ['Use healthy seed', 'Apply balanced fertiliser based on soil tests']],
                    ['name' => 'Bacterial leaf blight', 'severity' => 'high', 'desc' => 'A bacterial disease (Xanthomonas oryzae) spread by water and wounds.',
                        'symptoms' => ['Water-soaked to yellow stripes along leaf edges that turn white-grey', 'Wilting of seedlings in severe cases'],
                        'factors' => ['Typhoons, heavy rain and flooding', 'Excess nitrogen', 'Wounded leaves'],
                        'treat' => ['Drain affected fields where possible', 'Avoid excess nitrogen'],
                        'prevent' => ['Plant resistant varieties', 'Use clean seed', 'Avoid clipping seedling leaves at transplanting']],
                ],
            ],
        ];
    }
}
