<?php

namespace Database\Seeders;

use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class KnowledgeBaseSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $category = KnowledgeBaseCategory::create([
                'tenant_id' => $tenant->id,
                'name' => 'Getting Started',
                'description' => 'Basic setup and configuration guides',
            ]);

            KnowledgeBaseArticle::create([
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'title' => 'How to Configure Your Router',
                'content' => 'Step-by-step guide to configure your MikroTik router for PPPoE connection...',
                'views' => 150,
            ]);

            $this->command->line("  [{$tenant->slug}] Knowledge base seeded.");
        });

        $this->command->info('KnowledgeBaseSeeder: complete.');
    }
}
