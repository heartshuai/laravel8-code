<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Autoload;

/**
 * ClassLoader implements a PSR-0, PSR-4 and classmap class loader.
 *
 *     $loader = new \Composer\Autoload\ClassLoader();
 *
 *     // register classes with namespaces
 *     $loader->add('Symfony\Component', __DIR__.'/component');
 *     $loader->add('Symfony',           __DIR__.'/framework');
 *
 *     // activate the autoloader
 *     $loader->register();
 *
 *     // to enable searching the include path (eg. for PEAR packages)
 *     $loader->setUseIncludePath(true);
 *
 * In this example, if you try to use a class in the Symfony\Component
 * namespace or one of its children (Symfony\Component\Console for instance),
 * the autoloader will first look for the class under the component/
 * directory, and it will then fallback to the framework/ directory if not
 * found before giving up.
 *
 * This class is loosely based on the Symfony UniversalClassLoader.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @see    https://www.php-fig.org/psr/psr-0/
 * @see    https://www.php-fig.org/psr/psr-4/
 */
class ClassLoader
{
    /** @var \Closure(string):void */
    private static $includeFile;

    /** @var string|null */
    private $vendorDir;

    // PSR-4
    /**
     * @var array<string, array<string, int>>
     */
    private $prefixLengthsPsr4 = array();
    /**
     * @var array<string, list<string>>
     */
    private $prefixDirsPsr4 = array();
    /**
     * @var list<string>
     */
    private $fallbackDirsPsr4 = array();

    // PSR-0
    /**
     * List of PSR-0 prefixes
     *
     * Structured as array('F (first letter)' => array('Foo\Bar (full prefix)' => array('path', 'path2')))
     *
     * @var array<string, array<string, list<string>>>
     */
    private $prefixesPsr0 = array();
    /**
     * @var list<string>
     */
    private $fallbackDirsPsr0 = array();

    /** @var bool */
    private $useIncludePath = false;

    /**
     * @var array<string, string>
     */
    private $classMap = array();

    /** @var bool */
    private $classMapAuthoritative = false;

    /**
     * @var array<string, bool>
     */
    private $missingClasses = array();

    /** @var string|null */
    private $apcuPrefix;

    /**
     * @var array<string, self>
     */
    private static $registeredLoaders = array();

    /**
     * @param string|null $vendorDir
     */
    public function __construct($vendorDir = null)
    {
        $this->vendorDir = $vendorDir;
        self::initializeIncludeClosure();
    }

    /**
     * @return array<string, list<string>>
     */
    public function getPrefixes()
    {
        if (!empty($this->prefixesPsr0)) {
            return call_user_func_array('array_merge', array_values($this->prefixesPsr0));
        }

        return array();
    }

    /**
     * @return array<string, list<string>>
     */
    public function getPrefixesPsr4()
    {
        return $this->prefixDirsPsr4;
    }

    /**
     * @return list<string>
     */
    public function getFallbackDirs()
    {
        return $this->fallbackDirsPsr0;
    }

    /**
     * @return list<string>
     */
    public function getFallbackDirsPsr4()
    {
        return $this->fallbackDirsPsr4;
    }

    /**
     * @return array<string, string> Array of classname => path
     */
    public function getClassMap()
    {
        return $this->classMap;
    }

    /**
     * @param array<string, string> $classMap Class to filename map
     *
     * @return void
     */
    public function addClassMap(array $classMap)
    {
        if ($this->classMap) {
            $this->classMap = array_merge($this->classMap, $classMap);
        } else {
            $this->classMap = $classMap;
        }
    }

    /**
     * Registers a set of PSR-0 directories for a given prefix, either
     * appending or prepending to the ones previously set for this prefix.
     *
     * @param string              $prefix  The prefix
     * @param list<string>|string $paths   The PSR-0 root directories
     * @param bool                $prepend Whether to prepend the directories
     *
     * @return void
     */
    public function add($prefix, $paths, $prepend = false)
    {
        $paths = (array) $paths;
        if (!$prefix) {
            if ($prepend) {
                $this->fallbackDirsPsr0 = array_merge(
                    $paths,
                    $this->fallbackDirsPsr0
                );
            } else {
                $this->fallbackDirsPsr0 = array_merge(
                    $this->fallbackDirsPsr0,
                    $paths
                );
            }

            return;
        }

        $first = $prefix[0];
        if (!isset($this->prefixesPsr0[$first][$prefix])) {
            $this->prefixesPsr0[$first][$prefix] = $paths;

            return;
        }
        if ($prepend) {
            $this->prefixesPsr0[$first][$prefix] = array_merge(
                $paths,
                $this->prefixesPsr0[$first][$prefix]
            );
        } else {
            $this->prefixesPsr0[$first][$prefix] = array_merge(
                $this->prefixesPsr0[$first][$prefix],
                $paths
            );
        }
    }

    /**
     * Registers a set of PSR-4 directories for a given namespace, either
     * appending or prepending to the ones previously set for this namespace.
     *
     * @param string              $prefix  The prefix/namespace, with trailing '\\'
     * @param list<string>|string $paths   The PSR-4 base directories
     * @param bool                $prepend Whether to prepend the directories
     *
     * @throws \InvalidArgumentException
     *
     * @return void
     */
    public function addPsr4($prefix, $paths, $prepend = false)
    {
        $paths = (array) $paths;
        if (!$prefix) {
            // Register directories for the root namespace.
            if ($prepend) {
                $this->fallbackDirsPsr4 = array_merge(
                    $paths,
                    $this->fallbackDirsPsr4
                );
            } else {
                $this->fallbackDirsPsr4 = array_merge(
                    $this->fallbackDirsPsr4,
                    $paths
                );
            }
        } elseif (!isset($this->prefixDirsPsr4[$prefix])) {
            // Register directories for a new namespace.
            $length = strlen($prefix);
            if ('\\' !== $prefix[$length - 1]) {
                throw new \InvalidArgumentException("A non-empty PSR-4 prefix must end with a namespace separator.");
            }
            $this->prefixLengthsPsr4[$prefix[0]][$prefix] = $length;
            $this->prefixDirsPsr4[$prefix] = $paths;
        } elseif ($prepend) {
            // Prepend directories for an already registered namespace.
            $this->prefixDirsPsr4[$prefix] = array_merge(
                $paths,
                $this->prefixDirsPsr4[$prefix]
            );
        } else {
            // Append directories for an already registered namespace.
            $this->prefixDirsPsr4[$prefix] = array_merge(
                $this->prefixDirsPsr4[$prefix],
                $paths
            );
        }
    }

    /**
     * Registers a set of PSR-0 directories for a given prefix,
     * replacing any others previously set for this prefix.
     *
     * @param string              $prefix The prefix
     * @param list<string>|string $paths  The PSR-0 base directories
     *
     * @return void
     */
    public function set($prefix, $paths)
    {
        if (!$prefix) {
            $this->fallbackDirsPsr0 = (array) $paths;
        } else {
            $this->prefixesPsr0[$prefix[0]][$prefix] = (array) $paths;
        }
    }

    /**
     * Registers a set of PSR-4 directories for a given namespace,
     * replacing any others previously set for this namespace.
     *
     * @param string              $prefix The prefix/namespace, with trailing '\\'
     * @param list<string>|string $paths  The PSR-4 base directories
     *
     * @throws \InvalidArgumentException
     *
     * @return void
     */
    public function setPsr4($prefix, $paths)
    {
        if (!$prefix) {
            $this->fallbackDirsPsr4 = (array) $paths;
        } else {
            $length = strlen($prefix);
            if ('\\' !== $prefix[$length - 1]) {
                throw new \InvalidArgumentException("A non-empty PSR-4 prefix must end with a namespace separator.");
            }
            $this->prefixLengthsPsr4[$prefix[0]][$prefix] = $length;
            $this->prefixDirsPsr4[$prefix] = (array) $paths;
        }
    }

    /**
     * Turns on searching the include path for class files.
     *
     * @param bool $useIncludePath
     *
     * @return void
     */
    public function setUseIncludePath($useIncludePath)
    {
        $this->useIncludePath = $useIncludePath;
    }

    /**
     * Can be used to check if the autoloader uses the include path to check
     * for classes.
     *
     * @return bool
     */
    public function getUseIncludePath()
    {
        return $this->useIncludePath;
    }

    /**
     * Turns off searching the prefix and fallback directories for classes
     * that have not been registered with the class map.
     *
     * @param bool $classMapAuthoritative
     *
     * @return void
     */
    public function setClassMapAuthoritative($classMapAuthoritative)
    {
        $this->classMapAuthoritative = $classMapAuthoritative;
    }

    /**
     * Should class lookup fail if not found in the current class map?
     *
     * @return bool
     */
    public function isClassMapAuthoritative()
    {
        return $this->classMapAuthoritative;
    }

    /**
     * APCu prefix to use to cache found/not-found classes, if the extension is enabled.
     *
     * @param string|null $apcuPrefix
     *
     * @return void
     */
    public function setApcuPrefix($apcuPrefix)
    {
        $this->apcuPrefix = function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN) ? $apcuPrefix : null;
    }

    /**
     * The APCu prefix in use, or null if APCu caching is not enabled.
     *
     * @return string|null
     */
    public function getApcuPrefix()
    {
        return $this->apcuPrefix;
    }

    /**
     * Registers this instance as an autoloader.
     *
     * @param bool $prepend Whether to prepend the autoloader or not
     *
     * @return void
     */
    public function register($prepend = false)
    {
        // 使用 spl_autoload_register 注册自动加载函数，当尝试使用尚未被定义的类或接口时自动调用。
        // autoload 的实现在类的 loadClass 方法中。
        // 函数的第二个参数表示抛出异常 if the autoload_function cannot be registered.
        // 第三个参数 prepend 如果是 true，函数将先设置的 autoloader 插入队列的开头，否则它会插入队列的末尾。

        #spl_autoload_register 注册一次即可
        spl_autoload_register(array($this, 'loadClass'), true, $prepend);
//        $a=new A();
        // 如果 vendorDir 属性是 null，表示没有设置包的根目录，那么直接返回，后面的代码不再执行。
        if (null === $this->vendorDir) {
            return;
        }

        // 如果 prepend 为 true，将当前对象放在 $registeredLoaders 数组的开头
        if ($prepend) {
            self::$registeredLoaders = array($this->vendorDir => $this) + self::$registeredLoaders;
        } else {
            // 如果 prepend 为 false，先从 $registeredLoaders 数组中删除当前对象，然后再将当前对象添加到 $registeredLoaders 数组的末尾。
            unset(self::$registeredLoaders[$this->vendorDir]);
            self::$registeredLoaders[$this->vendorDir] = $this;
        }
    }

    /**
     * Unregisters this instance as an autoloader.
     *
     * @return void
     */
    public function unregister()
    {
        spl_autoload_unregister(array($this, 'loadClass'));

        if (null !== $this->vendorDir) {
            unset(self::$registeredLoaders[$this->vendorDir]);
        }
    }

    /**
     * Loads the given class or interface.
     *
     * @param  string    $class The name of the class
     * @return true|null True if loaded, null otherwise
     */
    public function loadClass($class)
    {
        // 调用 findFile 方法查找指定类的文件位置
        // 如果找到类文件，则 $file 为文件路径，否则为 null
        if ($file = $this->findFile($class)) {
            // 使用 $includeFile 回调函数来引入或包含文件
            $includeFile = self::$includeFile;

            // 调用 $includeFile 函数，引入类文件
            // 正常来说，这里应该是一个包含 PHP 类的 .php 文件
            $includeFile($file);

            // 加载文件成功后返回 true
            return true;
        }

        // 当找不到类文件时，返回 null
        return null;
    }

    /**
     * Finds the path to the file where the class is defined.
     *
     * @param string $class The name of the class
     *
     * @return string|false The path if found, false otherwise
     */
    public function findFile($class)
    {
        // 首先检查$this->classMap（类映射）中是否存在该类
        // 如果存在，直接返回对应的文件路径
        if (isset($this->classMap[$class])) {
//            return $this->classMap[$class];
        }

        // 接着检查类映射是否是权威的（包含了项目中的所有类）但该类不存在于类映射中
        // 或者该类已经在丢失的类集中，那么直接返回 false
        if ($this->classMapAuthoritative || isset($this->missingClasses[$class])) {
            return false;
        }

        // 如果启用了APCu缓存（即$this->apcuPrefix不为 null），
        // 那么检查该类的文件路径是否存储在APCu缓存中，如果命中，返回文件路径
        if (null !== $this->apcuPrefix) {
            $file = apcu_fetch($this->apcuPrefix.$class, $hit);
            if ($hit) {
                return $file;
            }
        }

        // 尝试找到拥有 '.php' 扩展名的类文件
        $file = $this->findFileWithExtension($class, '.php');
        var_dump($file);die;
        // 如果上一步没有找到，并且当前在 HHVM 环境下运行，尝试找到拥有 '.hh' 扩展名的文件
        if (false === $file && defined('HHVM_VERSION')) {
            $file = $this->findFileWithExtension($class, '.hh');
        }
//        var_dump($file);die;

        // 如果启用了 APCu 缓存，把找到的文件路径（如果文件存在）或 null 存储在 APCu 缓存中以供后续请求使用
        if (null !== $this->apcuPrefix) {
            apcu_add($this->apcuPrefix.$class, $file);
        }

        // 如果没有找到文件，把该类添加到丢失的类集
        if (false === $file) {
            $this->missingClasses[$class] = true;
        }


        // 最后，返回找到的文件路径，如果没有找到任何文件返回 false
        return $file;
    }

    /**
     * Returns the currently registered loaders keyed by their corresponding vendor directories.
     *
     * @return array<string, self>
     */
    public static function getRegisteredLoaders()
    {
        return self::$registeredLoaders;
    }

    /**
     * @param  string       $class
     * @param  string       $ext
     * @return string|false
     */
    private function findFileWithExtension($class, $ext)
    {
        // 根据psr-4规则生成类文件路径
        // 把类的完全限定名中的命名空间部分的'\'替换，请注意 DIRECTORY_SEPARATOR 是系统的目录分隔符，windows是'\'，linux是'/'
        $logicalPathPsr4 = strtr($class, '\\', DIRECTORY_SEPARATOR) . $ext;
        // 提取类名的第一个字符
        $first = $class[0];
        // 检查这个字符是否在 prefixLengthsPsr4 数组中
        if (isset($this->prefixLengthsPsr4[$first])) {
            $subPath = $class;
            // 对类名进行分割，查找相应的文件，直到不存在'\'为止
            while (false !== $lastPos = strrpos($subPath, '\\')) {
                // 去掉最后一个'\'以及其后面的字符，得到命名空间
                $subPath = substr($subPath, 0, $lastPos);

                // 在命名空间后加上'\'
                $search = $subPath . '\\';
                // 检查这个命名空间是否在 prefixDirsPsr4 数组中
                if (isset($this->prefixDirsPsr4[$search])) {
                    // 把命名空间后面的类名，转化成目录，然后加入到对应的命名空间的所有目录中，找到类文件
                    $pathEnd = DIRECTORY_SEPARATOR . substr($logicalPathPsr4, $lastPos + 1);
                    // 遍历对应命名空间的所有目录
                    foreach ($this->prefixDirsPsr4[$search] as $dir) {
                        // 检查文件是否存在，存在则返回文件路径
                        if (file_exists($file = $dir . $pathEnd)) {
                            return $file;
                        }
                    }
                }
            }
        }

        // 根据psr-4规则，在 fallbackDirsPsr4 中查找类文件
        foreach ($this->fallbackDirsPsr4 as $dir) {
            // 检查文件是否存在，存在则返回文件路径
            if (file_exists($file = $dir . DIRECTORY_SEPARATOR . $logicalPathPsr4)) {
                return $file;
            }
        }

        // 根据psr-0规则生成类文件路径
        if (false !== $pos = strrpos($class, '\\')) {
            // 对命名空间类名进行处理，得到类文件
            $logicalPathPsr0 = substr($logicalPathPsr4, 0, $pos + 1)
                . strtr(substr($logicalPathPsr4, $pos + 1), '_', DIRECTORY_SEPARATOR);
        } else {
            // PEAR类名处理
            $logicalPathPsr0 = strtr($class, '_', DIRECTORY_SEPARATOR) . $ext;
        }

        // 根据psr-0规则，在 prefixesPsr0 中查找类文件
        if (isset($this->prefixesPsr0[$first])) {
            // 遍历对应首字符的所有前缀
            foreach ($this->prefixesPsr0[$first] as $prefix => $dirs) {
                // 确保类名以前缀开始
                if (0 === strpos($class, $prefix)) {
                    // 遍历对应前缀的所有目录
                    foreach ($dirs as $dir) {
                        // 检查文件是否存在，存在则返回文件路径
                        if (file_exists($file = $dir . DIRECTORY_SEPARATOR . $logicalPathPsr0)) {
                            return $file;
                        }
                    }
                }
            }
        }

        // 根据psr-0规则，在 fallbackDirsPsr0 中查找类文件
        foreach ($this->fallbackDirsPsr0 as $dir) {
            if (file_exists($file = $dir . DIRECTORY_SEPARATOR . $logicalPathPsr0)) {
                return $file;
            }
        }

        // 如果在 include_path 中查找
        if ($this->useIncludePath && $file = stream_resolve_include_path($logicalPathPsr0)) {
            return $file;
        }

        // 如果在所有位置都没有找到，返回 false
        return false;
    }

    /**
     * @return void
     */
    private static function initializeIncludeClosure()
    {
        // 首先，检查静态变量 self::$includeFile 是否为 null
// 如果不为 null，就直接返回，这是一种避免重复初始化的方式
        if (self::$includeFile !== null) {
            return;
        }

        /**
         * Scope isolated include.
         *
         * Prevents access to $this/self from included files.
         *
         * @param  string$file
         * @returnvoid
         */
// 利用 \Closure::bind() 创建一个闭包，用于安全地包含 PHP 文件
// 第一个参数是匿名函数，实现文件的包含
// 第二个参数是类作用域（scope），这里设置为 null，表示闭包中不能使用 $this
// 第三个参数是绑定的对象，这里也设置为 null，表示闭包中不能访问 self
// 这样，创建的闭包就和当前类上下文隔离开，从而实现了由于包含文件误访问 $this 和 self:: 的安全问题
        self::$includeFile = \Closure::bind(static function ($file) {
            include $file;
        }, null, null);
    }
}
