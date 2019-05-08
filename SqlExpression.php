<?php

namespace Cms\Db\Query;


class SqlExpression
{
    const BIND_SKIP = -INF;  //спец. значение метки-заменителя для удаления условного блока
    const BIND_KEEP = +INF;  //спец. значение метки-заменителя для сохранения условного блока

    const CAST_TOKEN = '::';
    const CAST_REPLACEMENT = "\x01^^\x02";

    const BLOCK_OPEN_TAG  = '{';
    const BLOCK_CLOSE_TAG = '}';

    const IF_PREFIX    = '?';
    const VALUE_PREFIX = ':';
    const FIELD_PREFIX = '@';

    const ENCODE_QUOTED = [
        //"\x01" => "\x011\x02", //binary safe!
        //"\x02" => "\x012\x02", //binary safe!
        '?'    => "\x013\x02",
        ':'    => "\x014\x02",
        '@'    => "\x015\x02",
        '{'    => "\x016\x02",
        '}'    => "\x017\x02",
    ];

    const DECODE_QUOTED = [
        //"\x011\x02" => "\x01",
        //"\x012\x02" => "\x02",
        "\x013\x02" => '?',
        "\x014\x02" => ':',
        "\x015\x02" => '@',
        "\x016\x02" => '{',
        "\x017\x02" => '}',
    ];

    const PLACEHOLDER_PATTERN = '~ [?:@] [a-zA-Z_]++ [a-zA-Z_\d]*+ (?:\[\])? ~sxSX';

    /**
     * @var string
     */
    protected $sql;

    /**
     * SqlExpression constructor.
     * Напрямую вызвать конструктор нельзя, нужно использовать метод bind().
     *
     * @param string $sql
     */
    protected function __construct(string $sql)
    {
        $this->sql = $sql;
    }

    public function __toString() : string
    {
        return $this->sql;
    }

    /**
     * Шаблонизатор SQL
     * Документация: https://github.com/rin-nas/sql-template-engine/
     *
     * @param string $sql          Шаблон SQL запроса с необязательными метками-заменителями и условными блоками
     * @param array $placeholders  Ассоциативный массив, где
     *                                 ключи — это метки-заменители
     *                                 значения — это данные (любые типы), которые нужно заквотировать
     * @param object $quotation    Объект, отвечающий за квотирование. Должен иметь методы quote() и quoteField()
     *
     * @return SqlExpression   Объект, в котором хранится готовое sql выражение
     *                         Объект имеет метод __toString(), возвращающий готовое sql выражение
     *                         Объект позволяет вставлять готовые куски SQL друг в друга без повторного квотирования
     * @throws \Exception
     * @link   http://php.net/manual/en/pdo.prepare.php
     */
    public static function bind(string $sql, array $placeholders, $quotation) : SqlExpression
    {
        $hasBlocks = is_int($offset = strpos($sql, static::BLOCK_OPEN_TAG))
                  && is_int(strpos($sql, static::BLOCK_CLOSE_TAG, $offset));

        //prevent PostgreSQL value cast like 'field::text'
        $castTokenReplacedCount = 0;
        $sql = str_replace(static::CAST_TOKEN, static::CAST_REPLACEMENT, $sql, $castTokenReplacedCount);

        $placeholders = static::quote($placeholders, $quotation);

        //квотированные данные могут содержать спецсимволы, которые не являются частью настоящих меток-заменителей и парных блоков
        //закодируем эти спецсимволы, чтобы корректно работали замены настоящих меток-заменителей и парсинг парных блоков
        foreach ($placeholders as $key => $value) {
            $placeholders[$key] = strtr($value, static::ENCODE_QUOTED);
        }

        $sql = strtr($sql, $placeholders);

        if ($hasBlocks) {
            $tokens = static::tokenize($sql, static::BLOCK_OPEN_TAG, static::BLOCK_CLOSE_TAG);
            $tokens = static::removeUnused($tokens);
            $sql = static::unTokenize($tokens);
        }

        //если в SQL запросе остались неиспользуемые метки-заменители, то при его выполнении будет ошибка синтаксиса
        //лучше показать разработчику точную по смыслу ошибку с описанием проблемы
        $placeholder = static::getFirstPlaceholder($sql);
        if (is_string($placeholder)) {
            $openTag  = static::BLOCK_OPEN_TAG;
            $closeTag = static::BLOCK_CLOSE_TAG;
            throw new \Exception("Метка-заменитель '$placeholder' не была заменена (она находится не внутри блоков с парными тегами '$openTag' и '$closeTag'), т.к. в массиве замен ключ '$placeholder' отсутствует");
        }

        $sql = strtr($sql, static::DECODE_QUOTED);

        if ($castTokenReplacedCount > 0) {
            $sql = str_replace(static::CAST_REPLACEMENT, static::CAST_TOKEN, $sql);
        }
        return new SqlExpression($sql);
    }

    /**
     * Делает bind() для каждого элемента переданного массива
     *
     * @param string $sql        Шаблон SQL запроса с метками-заменителями
     * @param array  $values     Ассоциативный массив
     *                           Для строковых ключей массива в SQL шаблоне зарезервирована метка-заменитель @key
     *                           Для значений массива в SQL шаблоне зарезервированы метки-заменители:
     *                           :key, :row, :row[], :value, :value[], @value и для строковых ключей ещё @key
     * @param object $quotation  Объект, отвечающий за квотирование. Должен иметь методы quote() и quoteField()
     *
     * @return SqlExpression[]
     * @throws \Exception
     */
    public static function bindEach(string $sql, array $values, $quotation) : array
    {
        foreach ($values as $key => $value) {
            $placeholders = [
                ':key'     => $key,
                ':row'     => $value, //alias to :value
                ':row[]'   => $value, //alias to :value[]
                ':value'   => $value,
                ':value[]' => $value,
                '@value'   => $value,
            ];
            if (is_string($key)) {
                $placeholders['@key'] = $key;
            }
            $values[$key] = static::bind($sql, $placeholders, $quotation);
        }
        return $values;
    }

    /**
     * Квотирует элементы меток-заменителей
     *
     * @param array  $placeholders
     * @param object $quotation
     *
     * @return array  Возвращает ассоциативный массив, где каждый элемент является строкой с квотированными данными
     * @throws \Exception
     */
    protected static function quote(array $placeholders, $quotation) : array
    {
        foreach ($placeholders as $name => &$value) {
            if (! is_string($name)) {
                throw new \Exception('Ключи массива являются именованными метками-заменителями и должны быть строками. ');
            }
            if (strlen($name) > 1) {
                if (strpos($name, static::CAST_TOKEN) !== false) {
                    throw new \Exception("Метка-заменитель '$name' не должна содержать подстроку '".static::CAST_TOKEN."'");
                }

                if ($value === static::BIND_SKIP) {
                    unset($placeholders[$name]);
                    continue;
                }
                if ($value === static::BIND_KEEP) {
                    $value = '';
                    continue;
                }

                if ($name{0} === static::IF_PREFIX) {
                    if ($value) {
                        $value = '';
                    } else {
                        unset($placeholders[$name]);
                    }
                    continue;
                }

                if ($name{0} === static::VALUE_PREFIX || $name{0} === static::FIELD_PREFIX) {
                    $isArray = substr($name, -2, 2) === '[]';
                    $value = static::quoteValue($value, $isArray, $name{0}, $quotation);
                    continue;
                }
            }
            throw new \Exception("Формат метки-заменителя '$name' не поддерживается.");
        }
        return $placeholders;
    }

    /**
     * @param string|integer|float|bool|null|array|SqlExpression|\DateTime  $value
     * @param bool   $isArray
     * @param string $prefix
     * @param object $quotation
     *
     * @return string
     */
    protected static function quoteValue($value, bool $isArray, string $prefix, $quotation) : string
    {
        if ($isArray && is_array($value)) {
            foreach ($value as $k => $v) {
                if ($v instanceof SqlExpression) {
                    $value[$k] = $v->__toString();
                    continue;
                }
                $value[$k] = ($prefix === static::FIELD_PREFIX) ? $quotation->quoteField($v) : $quotation->quote($v);
            }
            return implode(', ', $value);
        }
        if ($value instanceof SqlExpression) {
            return $value->__toString();
        }
        return ($prefix === static::FIELD_PREFIX) ? $quotation->quoteField($value) : $quotation->quote($value);
    }

    /**
     * Разбивает строку на части по парным тегам, учитывая вложенность
     *
     * @param string $str
     * @param string $openTag
     * @param string $closeTag
     *
     * @return array        Возвращает массив, где каждый элемент -- это массив из части строки и уровня вложенности
     * @throws \Exception
     */
    protected static function tokenize(string $str, string $openTag, string $closeTag) : array
    {
        if ($openTag === $closeTag) {
            throw new \Exception("Парные теги '$openTag' и '$closeTag' не должны быть одинаковыми");
        }
        $level = 0;
        $tokens = [];
        $opens = explode($openTag, $str);
        foreach ($opens as $open) {
            $closes = explode($closeTag, $open);
            $tokens[] = [$closes[0], ++$level];
            unset($closes[0]);
            foreach ($closes as $close) {
                $tokens[] = [$close, --$level];
            }
        }
        if ($level !== 1) {
            throw new \Exception("Парность тегов '$openTag' и '$closeTag' не соблюдается, level=$level");
        }
        return $tokens;
    }

    /**
     * Обратная функция по отношению к tokeinze()
     *
     * @param array &$tokens
     *
     * @return string
     */
    protected static function unTokenize(array &$tokens) : string
    {
        $str = '';
        foreach ($tokens as $token) {
            $str .= $token[0];
        }
        return $str;
    }

    /**
     * Удаляет из массива элементы, в которых остались неиспользуемые метки-заменители
     *
     * @param array &$tokens
     *
     * @return array    Возвращает массив с той же структурой, что и массив на входе
     */
    protected static function removeUnused(array &$tokens) : array
    {
        $return = [];
        $removeLevel = null;
        foreach ($tokens as $index => $token) {
            [$str, $currentLevel] = $token;
            if ($removeLevel !== null && $removeLevel > $currentLevel) {
                for ($i = count($return); $i > 0; $i--) {
                    if ($return[$i - 1][1] < $removeLevel) {
                        break;
                    }
                    unset($return[$i - 1]);
                }
                $removeLevel = null;
            }
            if ($removeLevel === null && $currentLevel > 1 && static::getFirstPlaceholder($str) !== null) {
                $removeLevel = $currentLevel;
                continue;
            }
            if ($removeLevel === null) {
                $return[] = $token;
            }
        }
        return $return;
    }

    /**
     * Возвращает название первой найденной метки-заменителя в строке
     *
     * @param string $str
     *
     * @return string|null  Возвращает null, если ничего не найдено
     */
    protected static function getFirstPlaceholder(string $str) : ?string
    {
        $matches = [];
        foreach ([static::IF_PREFIX, static::VALUE_PREFIX, static::FIELD_PREFIX] as $char) {
            //speed improves by strpos()
            $offset = strpos($str, $char);
            if ($offset !== false
                && preg_match(static::PLACEHOLDER_PATTERN, $str, $matches, null, $offset) === 1) {
                return $matches[0];
            }
        }
        return null;
    }

}
