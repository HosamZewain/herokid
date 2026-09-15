<?php

namespace Tests\Unit;

use App\Support\StudioProductionRecipe;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudioProductionRecipeTest extends TestCase
{
    public function test_personalized_card_recipe_normalizes_portrait_60_by_90_black_border_zero_bleed_and_two_sides(): void
    {
        $recipe = StudioProductionRecipe::normalize('personalized-card', 1, StudioProductionRecipe::defaults());

        $this->assertSame('personalized-card', $recipe['workflow']);
        $this->assertSame(60.0, $recipe['canvas']['width_mm']);
        $this->assertSame(90.0, $recipe['canvas']['height_mm']);
        $this->assertSame(['portrait', 'landscape'], $recipe['canvas']['allowed_orientations']);
        $this->assertSame('portrait', $recipe['canvas']['default_orientation']);
        $this->assertSame([1, 2], $recipe['sides']['allowed']);
        $this->assertSame(1, $recipe['sides']['default']);
        $this->assertTrue($recipe['cut']['show_border']);
        $this->assertSame('#000000', $recipe['cut']['border_color']);
        $this->assertSame(0.0, $recipe['cut']['bleed_mm']);
    }

    public function test_landscape_and_one_side_configuration_are_valid(): void
    {
        $recipe = StudioProductionRecipe::defaults();
        $recipe['canvas']['allowed_orientations'] = ['landscape'];
        $recipe['canvas']['default_orientation'] = 'landscape';
        $recipe['sides'] = ['allowed' => [1], 'default' => 1];

        $normalized = StudioProductionRecipe::normalize('personalized-card', 1, $recipe);

        $this->assertSame(['landscape'], $normalized['canvas']['allowed_orientations']);
        $this->assertSame([1], $normalized['sides']['allowed']);
    }

    public function test_optional_inputs_include_two_separate_parent_phone_fields(): void
    {
        $recipe = StudioProductionRecipe::normalize('personalized-card', 1, StudioProductionRecipe::defaults());

        $this->assertContains('parent_phone_primary', $recipe['optional_inputs']);
        $this->assertContains('parent_phone_secondary', $recipe['optional_inputs']);
        $this->assertSame([], $recipe['required_inputs']);
    }

    #[DataProvider('supportedWorkflows')]
    public function test_each_allowlisted_workflow_is_accepted(string $workflow): void
    {
        $recipe = StudioProductionRecipe::defaults();
        $recipe['workflow'] = $workflow;

        $this->assertSame($workflow, StudioProductionRecipe::normalize($workflow, 1, $recipe)['workflow']);
    }

    public static function supportedWorkflows(): array
    {
        return array_combine(
            StudioProductionRecipe::WORKFLOWS,
            array_map(static fn (string $workflow): array => [$workflow], StudioProductionRecipe::WORKFLOWS),
        );
    }

    #[DataProvider('invalidRecipes')]
    public function test_invalid_recipe_data_is_rejected(string $workflow, int $version, callable $mutate): void
    {
        $recipe = StudioProductionRecipe::defaults();
        $mutate($recipe);

        $this->expectException(ValidationException::class);
        StudioProductionRecipe::normalize($workflow, $version, $recipe);
    }

    public static function invalidRecipes(): array
    {
        return [
            'unknown workflow' => ['arbitrary-workflow', 1, static function (array &$recipe): void {
                $recipe['workflow'] = 'arbitrary-workflow';
            }],
            'unknown version' => ['personalized-card', 99, static function (array &$recipe): void {
                $recipe['version'] = 99;
            }],
            'invalid width' => ['personalized-card', 1, static function (array &$recipe): void {
                $recipe['canvas']['width_mm'] = 0;
            }],
            'negative bleed' => ['personalized-card', 1, static function (array &$recipe): void {
                $recipe['cut']['bleed_mm'] = -1;
            }],
            'negative inset' => ['personalized-card', 1, static function (array &$recipe): void {
                $recipe['cut']['border_inset_mm'] = -0.5;
            }],
            'invalid color' => ['personalized-card', 1, static function (array &$recipe): void {
                $recipe['cut']['border_color'] = 'black';
            }],
            'script label' => ['personalized-card', 1, static function (array &$recipe): void {
                $recipe['template_variants'][0]['label'] = '<script>alert(1)</script>';
            }],
            'unknown output type' => ['personalized-card', 1, static function (array &$recipe): void {
                $recipe['output']['type'] = 'html';
            }],
            'unknown key' => ['personalized-card', 1, static function (array &$recipe): void {
                $recipe['executable'] = 'javascript:alert(1)';
            }],
            'default orientation not allowed' => ['personalized-card', 1, static function (array &$recipe): void {
                $recipe['canvas']['allowed_orientations'] = ['landscape'];
            }],
            'input required and optional' => ['personalized-card', 1, static function (array &$recipe): void {
                $recipe['required_inputs'] = ['child_name'];
            }],
        ];
    }
}
