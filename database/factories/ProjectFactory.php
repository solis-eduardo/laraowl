<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => $this->faker->company(),
            'slug' => fn (array $attributes) => Str::slug($attributes['name']),
            'api_token' => Str::random(64),
            'url' => $this->faker->url(),
            'uptime_monitoring_enabled' => true,
            'settings' => [],
        ];
    }

    /**
     * Indicate that the project should not be checked for uptime.
     */
    public function withoutUptimeMonitoring(): static
    {
        return $this->state(fn (array $attributes) => [
            'uptime_monitoring_enabled' => false,
        ]);
    }
}
