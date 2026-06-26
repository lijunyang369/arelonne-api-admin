<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * 创建管理员账号，用于登录 HOPE Admin 后台。
     *
     * 默认账号：admin@hope.com / admin123456
     * 生产环境部署后请立即修改密码。
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@hope.com'],
            [
                'name'     => 'Admin',
                'email'    => 'admin@hope.com',
                'password' => Hash::make('admin123456'),
            ]
        );

        $this->command?->info('✅ Admin account ready: admin@hope.com / admin123456');
    }
}
