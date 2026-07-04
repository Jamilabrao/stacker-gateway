<?php



namespace Tests\Unit;



use App\Models\PlatformIntegraxSetting;

use App\Support\IntegraxCartRecoverySteps;

use Tests\TestCase;



class IntegraxCartRecoveryStepsTest extends TestCase

{

    public function test_converts_ui_steps_to_minutes(): void

    {

        $steps = IntegraxCartRecoverySteps::fromUiInput([

            ['delay_value' => 10, 'delay_unit' => 'minutes', 'message' => 'A'],

            ['delay_value' => 2, 'delay_unit' => 'hours', 'message' => 'B'],

            ['delay_value' => 1, 'delay_unit' => 'days', 'message' => 'C'],

        ]);



        $this->assertSame(10, $steps[0]['delay_minutes']);

        $this->assertSame(120, $steps[1]['delay_minutes']);

        $this->assertSame(1440, $steps[2]['delay_minutes']);

    }



    public function test_null_recovery_steps_uses_defaults(): void
    {
        $settings = PlatformIntegraxSetting::instance();
        $settings->update([
            'cart_recovery_steps' => null,
            'message_cart_recovery' => 'Legacy {nome}',
            'cart_first_delay_minutes' => 10,
            'cart_interval_minutes' => 60,
            'cart_max_duration_hours' => 3,
            'cart_max_sends' => 2,
        ]);

        $steps = IntegraxCartRecoverySteps::forSetting($settings->fresh());

        $this->assertCount(3, $steps);
        $this->assertSame(10, $steps[0]['delay_minutes']);
        $this->assertSame(1440, $steps[1]['delay_minutes']);
        $this->assertSame(2880, $steps[2]['delay_minutes']);
    }

}

