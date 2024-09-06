<?php

namespace Illuminate\Foundation;

use Exception;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class Mix
{
    /**
     * Get the path to a versioned Mix file.
     *
     * @param  string  $path
     * @param  string  $manifestDirectory
     * @return \Illuminate\Support\HtmlString|string
     *
     * @throws \Exception
     */
    public function __invoke($path, $manifestDirectory = '')
    {
        /* 用于临时存储已经读取过的 Mix manifest 文件内容 */
        static $manifests = [];

        /* 处理文件路径，确保文件路径是以斜杠开头 */
        if (!Str::startsWith($path, '/')) {
            $path = "/{$path}";
        }

        /* 处理 manifest 文件的目录名，确保目录名是以斜杠开头 */
        if ($manifestDirectory && ! Str::startsWith($manifestDirectory, '/')) {
            $manifestDirectory = "/{$manifestDirectory}";
        }

        /* 读取热更新（HMR）文件，如果热更新文件存在，则每次请求都返回最新的文件 */
        if (is_file(public_path($manifestDirectory.'/hot'))) {
            $url = rtrim(file_get_contents(public_path($manifestDirectory.'/hot')));

            $customUrl = app('config')->get('app.mix_hot_proxy_url');

            /* 如果配置里有自定义的HMR URL，优先使用 */
            if (!empty($customUrl)) {
                return new HtmlString("{$customUrl}{$path}");
            }

            /* 如果热更新(HMR)的URL包含协议头，则移除协议头并返回 */
            if (Str::startsWith($url, ['http://', 'https://'])) {
                return new HtmlString(Str::after($url, ':').$path);
            }

            /* 默认情况下，热更新的服务运行在localhost的8080端口上 */
            return new HtmlString("//localhost:8080{$path}");
        }

        /* Mix manifest 文件的路径*/
        $manifestPath = public_path($manifestDirectory.'/mix-manifest.json');

        /* 如果 manifest 文件还未被读取过，则读取 manifest 文件，并保存到 manifests 数组 */
        if (!isset($manifests[$manifestPath])) {
            if (!is_file($manifestPath)) {
                throw new Exception('The Mix manifest does not exist.');
            }
            $manifests[$manifestPath] = json_decode(file_get_contents($manifestPath), true);
        }

        /* 从 manifests 数组中获取该 manifest 文件内容 */
        $manifest = $manifests[$manifestPath];

        /* 如果在 manifest 文件中找不到路径对应的文件，抛出异常。如果应用不处于debug模式，返回原路径。 */
        if (!isset($manifest[$path])) {
            $exception = new Exception("Unable to locate Mix file: {$path}.");

            if (!app('config')->get('app.debug')) {
                report($exception);
                return $path;
            } else {
                throw $exception;
            }
        }

        /* 构建并返回文件的 URL，URL由应用的 mix_url 配置项，manifest 文件目录以及在 manifest 文件中找到的文件路径拼接而成 */
        return new HtmlString(
            app('config')->get('app.mix_url') .
            $manifestDirectory .
            $manifest[$path]);
    }
}
