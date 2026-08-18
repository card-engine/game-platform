<?php

namespace app\service\database;

use app\service\game\ConfigService;
use app\model\Enterprise;
use app\model\Merchant;
use app\model\MerchantCredit;
use app\service\game\SecretService;
use app\service\mgs\MgsConfigService;
use plugin\saiadmin\app\cache\ConfigCache;
use plugin\saiadmin\app\cache\UserAuthCache;
use plugin\saiadmin\app\cache\UserMenuCache;
use RuntimeException;
use support\Db;

class SystemDataService
{
    public function sync(): void
    {
        $data = require base_path('database/system.php');
        Db::transaction(function () use ($data) {
            $menus = $this->menus($data['menus']);
            $roles = $this->roles($data['roles'], $menus);
            $this->configs($data['config_groups'], $data['configs']);
            $this->dicts($data['dict_types']);
            $this->categories($data['categories']);
            $this->department($data['department']);
            $this->gameConfigs($data['game_configs']);
            $selfMchId = $this->selfMerchant();
            $this->mgsConfigs($data['mgs_configs'] ?? [], $selfMchId);
            $this->gameUsers($data['game_users']);
            $this->mgsUsers($data['mgs_users'] ?? []);
            $this->crontabs($data['crontabs']);
            $this->users($data['users'], $roles);
        });

        ConfigCache::clear();
        UserAuthCache::clear();
        UserMenuCache::clearMenuCache();
        (new ConfigService())->rebuild();
        (new MgsConfigService())->rebuild();
    }

    private function menus(array $menus): array
    {
        $defaults = [
            'parent_id' => 0, 'code' => null, 'slug' => null, 'type' => 1, 'path' => null,
            'component' => null, 'method' => null, 'icon' => null, 'sort' => 100, 'link_url' => null,
            'is_iframe' => 2, 'is_keep_alive' => 2, 'is_hidden' => 2, 'is_fixed_tab' => 2,
            'is_full_page' => 2, 'generate_id' => 0, 'generate_key' => null, 'status' => 1, 'remark' => null,
        ];
        $ids = [];
        foreach ($menus as $menu) {
            $values = array_merge($defaults, array_diff_key($menu, array_flip(['key', 'parent'])));
            $where = ($values['code'] ?? '') !== '' ? ['code' => $values['code']] : ['slug' => $values['slug']];
            $ids[$menu['key']] = $this->upsert('sa_system_menu', $where, $values);
        }
        foreach ($menus as $menu) {
            Db::table('sa_system_menu')->where('id', $ids[$menu['key']])->update([
                'parent_id' => $menu['parent'] ? $ids[$menu['parent']] : 0,
                'update_time' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        return $ids;
    }

    private function roles(array $roles, array $menus): array
    {
        $ids = [];
        foreach ($roles as $code => $role) {
            $permissions = $role['menus'];
            unset($role['menus']);
            $ids[$code] = $this->upsert('sa_system_role', ['code' => $code], $role + ['code' => $code]);
            Db::table('sa_system_role_menu')->where('role_id', $ids[$code])->delete();
            $menuIds = $permissions === '*' ? array_values($menus) : array_values(array_intersect_key($menus, array_flip($permissions)));
            if ($menuIds) Db::table('sa_system_role_menu')->insert(array_map(fn ($id) => ['role_id' => $ids[$code], 'menu_id' => $id], $menuIds));
        }
        return $ids;
    }

    private function configs(array $groups, array $configs): void
    {
        $ids = [];
        foreach ($groups as $code => $group) $ids[$code] = $this->upsert('sa_system_config_group', ['code' => $code], $group + ['code' => $code]);
        foreach ($configs as $config) {
            $value = $config['value'];
            $config['group_id'] = $ids[$config['group']];
            unset($config['group'], $config['value']);
            $this->upsert('sa_system_config', ['key' => $config['key']], $config, ['value' => $value]);
        }
    }

    private function dicts(array $types): void
    {
        foreach ($types as $code => $type) {
            $data = $type['data'];
            unset($type['data']);
            $typeId = $this->upsert('sa_system_dict_type', ['code' => $code], $type + ['code' => $code]);
            foreach ($data as $item) $this->upsert('sa_system_dict_data', ['type_id' => $typeId, 'value' => $item['value']], $item + ['type_id' => $typeId]);
        }
    }

    private function categories(array $categories): void
    {
        $ids = [];
        foreach ($categories as $category) {
            $values = array_diff_key($category, array_flip(['key', 'parent'])) + ['parent_id' => 0, 'level' => '0,'];
            $ids[$category['key']] = $this->upsert('sa_system_category', ['category_name' => $category['key']], $values + ['category_name' => $category['key']]);
        }
        foreach ($categories as $category) {
            $parentId = $category['parent'] ? $ids[$category['parent']] : 0;
            Db::table('sa_system_category')->where('id', $ids[$category['key']])->update([
                'parent_id' => $parentId,
                'level' => $parentId ? "0,{$parentId}," : '0,',
                'update_time' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    private function department(array $department): void
    {
        $this->upsert('sa_system_dept', ['id' => 1], $department + [
            'id' => 1, 'parent_id' => 0, 'leader_id' => 1, 'level' => '0,',
        ]);
    }

    private function gameConfigs(array $configs): void
    {
        foreach ($configs as $config) {
            $value = json_encode($config['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $status = $config['status'];
            unset($config['value'], $config['status']);
            $this->upsert('mg_configs', ['code' => $config['code']], $config, ['value' => $value, 'status' => $status]);
        }
    }

    private function mgsConfigs(array $configs, string $selfMchId): void
    {
        foreach ($configs as $config) {
            if ($config['code'] === 'game_platform_mch_id' && $config['value'] === '') $config['value'] = $selfMchId;
            $value = json_encode($config['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $status = $config['status'] ?? 1;
            unset($config['value'], $config['status']);
            $this->upsert('mgs_configs', ['code' => $config['code']], $config, ['value' => $value, 'status' => $status]);
            if ($config['code'] === 'game_platform_mch_id' && $selfMchId !== '') {
                $stored = Db::table('mgs_configs')->where('code', $config['code'])->value('value');
                if (json_decode((string) $stored, true) === '') Db::table('mgs_configs')->where('code', $config['code'])->update(['value' => json_encode($selfMchId)]);
            }
        }
    }

    private function gameUsers(array $users): void
    {
        foreach ($users as $user) {
            $user['merchant_user_id'] = (string) config('game_platforms.self_merchant.user_id');
            $this->upsert('mg_users', ['id' => $user['id']], $user);
        }
    }

    private function mgsUsers(array $users): void
    {
        foreach ($users as $user) $this->upsert('mgs_users', ['id' => $user['id']], $user);
    }

    private function selfMerchant(): string
    {
        $mchId = trim((string) env('MGS_GAME_PLATFORM_MCH_ID', ''));
        $secret = (string) config('mgs.secret');
        $callbackUrl = (string) config('mgs.callback_url');
        if ($secret === '') throw new RuntimeException('首次安装必须配置 MGS_GAME_PLATFORM_SECRET');
        $merchant = $mchId !== '' ? Merchant::where('mch_id', $mchId)->first() : Merchant::where('name', 'MGS 自营平台')->first();
        if (!$merchant) {
            $enterprise = Enterprise::firstOrCreate(['name' => 'MGS 自营平台'], [
                'merchant_limit' => 1, 'timezone' => env('MGS_TIMEZONE', 'UTC'), 'default_language' => env('MGS_DEFAULT_LANGUAGE', 'en'), 'status' => 1,
            ]);
            $merchant = Merchant::create([
                'enterprise_id' => $enterprise->id, 'mch_id' => $mchId ?: null, 'name' => 'MGS 自营平台', 'wallet_mode' => 1,
                'billing_mode' => 1, 'callback_url' => $callbackUrl,
                'secret' => SecretService::encrypt($secret), 'language_codes' => [env('MGS_DEFAULT_LANGUAGE', 'en')],
                'default_language' => env('MGS_DEFAULT_LANGUAGE', 'en'), 'timezone' => env('MGS_TIMEZONE', 'UTC'), 'status' => 1,
            ]);
            if ($mchId === '') {
                $mchId = (string) id2big((int) $merchant->id);
                $merchant->update(['mch_id' => $mchId]);
            }
            MerchantCredit::create([
                'merchant_id' => $merchant->id, 'currency_code' => strtoupper((string) env('MGS_DEFAULT_CURRENCY', 'USD')),
                'rate_value' => '0.0300000000', 'settlement_enabled' => 1, 'status' => 1,
            ]);
        } else {
            $mchId = (string) $merchant->mch_id;
            $updates = [];
            if ((string) $merchant->callback_url !== $callbackUrl) $updates['callback_url'] = $callbackUrl;
            if (SecretService::decrypt($merchant->getRawOriginal('secret')) !== $secret) $updates['secret'] = SecretService::encrypt($secret);
            if ($updates) $merchant->update($updates);
        }
        MerchantCredit::firstOrCreate(
            ['merchant_id' => $merchant->id, 'currency_code' => strtoupper((string) env('MGS_DEFAULT_CURRENCY', 'USD'))],
            ['rate_value' => '0.0300000000', 'settlement_enabled' => 1, 'status' => 1],
        );
        return $mchId;
    }

    private function crontabs(array $crontabs): void
    {
        $names = [];
        foreach ($crontabs as $crontab) {
            $status = $crontab['status'];
            unset($crontab['status']);
            $names[] = $crontab['name'];
            $this->upsert('sa_tool_crontab', ['name' => $crontab['name']], $crontab, ['status' => $status]);
        }
        Db::table('sa_tool_crontab')->where(fn ($query) => $query->where('name', 'like', 'MG %')->orWhere('name', 'like', 'MGS %'))->whereNotIn('name', $names)->update([
            'status' => 2,
            'update_time' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function users(array $users, array $roles): void
    {
        foreach ($users as $user) {
            $account = Db::table('sa_system_user')->where('username', $user['username'])->first();
            if (!$account) {
                $password = (string) env($user['password_env'], '');
                if ($password === '') throw new RuntimeException("首次安装必须配置 {$user['password_env']}");
                if (isset($user['id']) && Db::table('sa_system_user')->where('id', $user['id'])->exists()) {
                    throw new RuntimeException("管理员 ID {$user['id']} 已被占用");
                }
                $id = Db::table('sa_system_user')->insertGetId(array_filter([
                    'id' => $user['id'] ?? null,
                    'username' => $user['username'],
                    'password' => password_hash($password, PASSWORD_BCRYPT),
                    'realname' => $user['realname'],
                    'dashboard' => 'work',
                    'dept_id' => 1,
                    'is_super' => $user['username'] === 'admin' ? 1 : 0,
                    'status' => 1,
                    'remark' => '系统内置账号',
                    'create_time' => gmdate('Y-m-d H:i:s'),
                    'update_time' => gmdate('Y-m-d H:i:s'),
                ], fn ($value) => $value !== null));
            } else {
                $id = $account->id;
                if (isset($user['id']) && (int) $user['id'] !== (int) $id) throw new RuntimeException("{$user['username']} 必须使用管理员 ID {$user['id']}");
                Db::table('sa_system_user')->where('id', $id)->update(['delete_time' => null]);
            }
            Db::table('sa_system_user_role')->where('user_id', $id)->delete();
            Db::table('sa_system_user_role')->insert(['user_id' => $id, 'role_id' => $roles[$user['role']]]);
        }
    }

    private function upsert(string $table, array $where, array $values, array $insertOnly = []): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = Db::table($table)->where($where)->orderBy('id')->first();
        if ($row) {
            Db::table($table)->where('id', $row->id)->update($values + ['update_time' => $now, 'delete_time' => null]);
            return (int) $row->id;
        }
        return (int) Db::table($table)->insertGetId($values + $insertOnly + ['create_time' => $now, 'update_time' => $now]);
    }
}
