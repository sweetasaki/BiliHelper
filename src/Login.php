<?php

/**
 *  Website: https://mudew.com/
 *  Author: Lkeme
 *  License: The MIT License
 *  Updated: 2018
 */

namespace lkeme\BiliHelper;

class Login
{
    public static $lock = 0;

    public static function run()
    {
        Log::info('开始启动程序...');
        if (getenv('ACCESS_TOKEN') == "") {
            Log::info('令牌载入中...');
            self::login();
        }
        // 重载配置文件
        Index::$dotenv->overload();

        Log::info('正在检查令牌合法性...');
        if (!self::info()) {
            Log::warning('令牌即将过期');
            Log::info('申请更换令牌中...');
            if (!self::refresh()) {
                Log::warning('无效令牌，正在重新申请...');
                self::login();
            }
        }
        self::$lock = time() + 3600;
    }

    public static function check()
    {
        if (self::$lock > time()) {
            return true;
        }
        self::$lock = time() + 7200;
        if (!self::info()) {
            Log::warning('令牌即将过期');
            Log::info('申请更换令牌中...');
            if (!self::refresh()) {
                Log::warning('无效令牌，正在重新申请...');
                self::login();
            }
            return false;
        }
        return true;
    }

    protected static function info()
    {
        $access_token = getenv('ACCESS_TOKEN');
        $payload = [
            'access_token' => $access_token,
        ];
        $data = Curl::get('https://passport.bilibili.com/api/oauth2/info', Sign::api($payload));
        $data = json_decode($data, true);
        if (isset($data['code']) && $data['code']) {
            Log::error('检查令牌失败', ['msg' => $data['message']]);
            return false;
        }
        Log::info('令牌有效期: ' . date('Y-m-d H:i:s', $data['ts'] + $data['data']['expires_in']));
        return $data['data']['expires_in'] > 86400;
    }

    public static function refresh()
    {
        $access_token = getenv('ACCESS_TOKEN');
        $refresh_token = getenv('REFRESH_TOKEN');
        $payload = [
            'access_token' => $access_token,
            'refresh_token' => $refresh_token,
        ];
        $data = Curl::post('https://passport.bilibili.com/api/oauth2/refreshToken', Sign::api($payload));
        $data = json_decode($data, true);
        if (isset($data['code']) && $data['code']) {
            Log::error('重新生成令牌失败', ['msg' => $data['message']]);
            return false;
        }
        Log::info('令牌生成完毕!');
        $access_token = $data['data']['access_token'];
        File::writeNewEnvironmentFileWith('ACCESS_TOKEN', $access_token);
        Log::info(' > access token: ' . $access_token);
        $refresh_token = $data['data']['refresh_token'];
        File::writeNewEnvironmentFileWith('REFRESH_TOKEN', $refresh_token);
        Log::info(' > refresh token: ' . $refresh_token);
        return true;
    }

    protected static function login()
    {
        $user = getenv('APP_USER');
        $pass = getenv('APP_PASS');
        if (empty($user) || empty($pass)) {
            Log::error('空白的帐号和口令!');
            die();
        }
        // get PublicKey
        Log::info('正在载入安全模块...');
        $payload = [];
        $data = Curl::post('https://passport.bilibili.com/api/oauth2/getKey', Sign::api($payload));
        $data = json_decode($data, true);
        if (isset($data['code']) && $data['code']) {
            Log::error('公钥获取失败', ['msg' => $data['message']]);
            die();
        } else {
            Log::info('安全模块载入完毕！');
        }
        $public_key = $data['data']['key'];
        $hash = $data['data']['hash'];
        openssl_public_encrypt($hash . $pass, $crypt, $public_key);
        // login
        Log::info('正在获取令牌...');
        $payload = [
            'subid' => 1,
            'permission' => 'ALL',
            'username' => $user,
            'password' => base64_encode($crypt),
            'captcha' => '',
        ];
        $data = Curl::post('https://passport.bilibili.com/api/v2/oauth2/login', Sign::api($payload));
        $data = json_decode($data, true);
        if (isset($data['code']) && $data['code']) {
            Log::error('登录失败', ['msg' => $data['message']]);
            die();
        }
        self::saveCookie($data);
        Log::info('令牌获取成功!');
        $access_token = $data['data']['token_info']['access_token'];
        File::writeNewEnvironmentFileWith('ACCESS_TOKEN', $access_token);
        Log::info(' > access token: ' . $access_token);
        $refresh_token = $data['data']['token_info']['refresh_token'];
        File::writeNewEnvironmentFileWith('REFRESH_TOKEN', $refresh_token);
        Log::info(' > refresh token: ' . $refresh_token);

        return;
    }

    private static function saveCookie($data)
    {
        Log::info('COOKIE获取成功!');
        //临时保存cookie
        $temp = '';
        $cookies = $data['data']['cookie_info']['cookies'];
        foreach ($cookies as $cookie) {
            $temp .= $cookie['name'] . '=' . $cookie['value'] . ';';
        }
        File::writeNewEnvironmentFileWith('COOKIE', $temp);
        Log::info(' > auth cookie: ' . $temp);
        return;
    }

}