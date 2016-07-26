<?php
namespace Phwoolcon;

use Phalcon\Di;
use Phalcon\Events\Event;
use Phalcon\Translate\Adapter;

class I18n extends Adapter
{
    /**
     * @var Di
     */
    protected static $di;
    /**
     * @var static
     */
    protected static $instance;
    protected $locale = [];
    protected $localePath;
    protected $defaultLocale;
    protected $currentLocale;
    protected $multiLocale = false;
    protected $detectClientLocale = false;
    protected $options = [];
    protected $availableLocales = [];
    /**
     * @var \Phalcon\Http\Request
     */
    protected $request;
    protected $undefinedStrings = [];
    protected $undefinedStringsLogFile;

    public function __construct(array $options = [])
    {
        parent::__construct($options);
        $this->multiLocale = $options['multi_locale'];
        $this->multiLocale and $this->availableLocales = $options['available_locales'];
        $this->detectClientLocale = $options['detect_client_locale'];
        $this->defaultLocale = $this->currentLocale = $options['default_locale'];
        $this->localePath = $options['locale_path'];
        $this->options = $options;
        $this->loadLocale($this->defaultLocale);
        $this->undefinedStringsLogFile = storagePath($options['undefined_strings_log']);
        is_file($this->undefinedStringsLogFile) and $this->undefinedStrings = include $this->undefinedStringsLogFile;
        $this->_reset();
    }

    protected function _reset()
    {
        if (!$this->multiLocale) {
            return;
        }
        $this->request or $this->request = static::$di->getShared('request');
        if ($locale = $this->request->get('_locale')) {
            $this->_setLocale($locale);
            return;
        }
        if (($cookie = Cookies::get('locale')) && $locale = $cookie->useEncryption(false)->getValue()) {
            $this->_setLocale($locale);
            $cookie->setHttpOnly(false)
                ->delete();
            return;
        }
        if ($locale = Session::get('current_locale')) {
            $this->loadLocale($this->currentLocale = $locale);
            return;
        }
        // @codeCoverageIgnoreStart
        if ($this->detectClientLocale) {
            $this->_setLocale($this->request->getBestLanguage());
            return;
        }
        // @codeCoverageIgnoreEnd
        $this->loadLocale($this->currentLocale = $this->defaultLocale);
    }

    protected function _setLocale($locale)
    {
        if (strpos($locale, '..') || !is_dir($this->localePath . '/' . $locale)) {
            $locale = $this->defaultLocale;
        }
        Session::set('current_locale', $this->currentLocale = $locale);
        $this->loadLocale($locale);
    }

    public static function checkMobile($mobile, $country = 'CN')
    {
        static::$instance or static::$instance = static::$di->getShared('i18n');
        $pattern = fnGet(static::$instance->options, "verification_patterns.{$country}.mobile");
        return $pattern ? (bool)preg_match($pattern, $mobile) : true;
    }

    public static function clearCache($memoryOnly = false)
    {
        static::$instance or static::$instance = static::$di->getShared('i18n');
        static::$instance->locale = [];
        if ($memoryOnly) {
            return;
        }
        foreach (glob(static::$instance->localePath . '/*', GLOB_ONLYDIR) as $dir) {
            $locale = basename($dir);
            Cache::delete('locale.' . $locale);
        }
        is_file(static::$instance->undefinedStringsLogFile) and unlink(static::$instance->undefinedStringsLogFile);
        static::$instance->undefinedStrings = [];
    }

    public static function formatPrice($amount, $currency = 'CNY')
    {
        // TODO Use I18n config
        $symbol = '￥';
        $precision = 2;
        $number = number_format($amount, $precision);
        $sequence = 'symbolFirst';
        return $sequence == 'symbolFirst' ? $symbol . $number : $number . $symbol;
    }

    public static function getAvailableLocales()
    {
        static::$instance or static::$instance = static::$di->getShared('i18n');
        return static::$instance->availableLocales;
    }

    public static function getCurrentLocale()
    {
        static::$instance or static::$instance = static::$di->getShared('i18n');
        return static::$instance->currentLocale;
    }

    public function loadLocale($locale, $force = false)
    {
        if (isset($this->locale[$locale]) && !$force) {
            return $this;
        }
        $useCache = $this->options['cache_locale'];
        $cacheKey = 'locale.' . $locale;
        if ($useCache && !$force && $cached = Cache::get($cacheKey)) {
            $this->locale[$locale] = $cached;
            return $this;
        }
        $packages = [];
        $combined = [];
        foreach (glob($this->localePath . '/' . $this->currentLocale . '/*.php') as $file) {
            $package = pathinfo($file, PATHINFO_FILENAME);
            $combined = array_merge($combined, $packages[$package] = (array)include $file);
        }
        $this->locale[$locale] = compact('combined', 'packages');
        $useCache and Cache::set($cacheKey, $this->locale[$locale]);
        return $this;
    }

    protected function logUndefinedLocaleString($string, $package)
    {
        $package = (string)$package;
        if (isset($this->undefinedStrings[$this->currentLocale][$package][$string])) {
            return;
        }
        Log::warning("I18n: locale string not found: '{$string}'");
        is_file($this->undefinedStringsLogFile) and $this->undefinedStrings = include $this->undefinedStringsLogFile;
        $this->undefinedStrings[$this->currentLocale][$package][$string] = true;
        file_put_contents(
            $this->undefinedStringsLogFile,
            sprintf('<?php return %s;', var_export($this->undefinedStrings, true))
        );
    }

    public function query($string, $params = null, $package = null)
    {
        if ($package && isset($this->locale[$this->currentLocale]['packages'][$package][$string])) {
            $translation = $this->locale[$this->currentLocale]['packages'][$package][$string];
        } elseif (isset($this->locale[$this->currentLocale]['combined'][$string])) {
            $translation = $this->locale[$this->currentLocale]['combined'][$string];
        } else {
            $this->logUndefinedLocaleString($string, $package);
            $translation = $string;
        }
        return $this->replacePlaceholders($translation, $params);
    }

    public static function register(Di $di)
    {
        static::$di = $di;
        $di->setShared('i18n', function () {
            return new static(Config::get('i18n'));
        });
        Events::attach('view:generatePhwoolconJsOptions', function (Event $event) {
            $options = $event->getData() ?: [];
            $options['locale'] = static::getCurrentLocale();
            $event->setData($options);
            return $options;
        });
    }

    public static function reset()
    {
        static::$instance or static::$instance = static::$di->getShared('i18n');
        static::$instance->_reset();
    }

    public static function setLocale($locale)
    {
        static::$instance or static::$instance = static::$di->getShared('i18n');
        static::$instance->_setLocale($locale);
    }

    public static function translate($string, array $params = null, $package = null)
    {
        static::$instance or static::$instance = static::$di->getShared('i18n');
        return static::$instance->query($string, $params, $package);
    }

    public static function useMultiLocale($flag = null)
    {
        static::$instance or static::$instance = static::$di->getShared('i18n');
        $flag === null or static::$instance->multiLocale = (bool)$flag;
        return static::$instance->multiLocale;
    }
}
