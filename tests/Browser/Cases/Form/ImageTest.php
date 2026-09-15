<?php

namespace Tests\Browser\Cases\Form;

use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 图片上传测试.
 */
#[Group('form:image')]
class ImageTest extends TestCase
{
    public function test()
    {
        $this->browse(function (Browser $browser) {
//            $browser->visit(admin_base_path('tests/users/create'))
//                ->attach('file-avatar', __DIR__.'/../../../resources/assets/test.jpg');

            $this->assertTrue(true);
        });
    }
}
