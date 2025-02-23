<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Repositories;

use App\Models\Client;
use App\Models\GroupSetting;

class GroupSettingRepository extends BaseRepository
{
    public function save($data, GroupSetting $group_setting) :?GroupSetting
    {

        $group_setting->fill($data);
        $group_setting->save();

        if (array_key_exists('company_logo', $data) && $data['company_logo'] == '') {
            $settings = $group_setting->settings;
            unset($settings->company_logo);
            $group_setting->settings = $settings;
            $group_setting->save();
        }

        nlog($data['settings']);

        if(count((array)$data['settings']) == 0){
            $settings = new \stdClass;
            $settings->entity = Client::class;
            $group_setting->settings = $settings;
            $group_setting->save();
        }

        return $group_setting;
    }
}
