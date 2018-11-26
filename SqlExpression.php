<?php

namespace Cms\Db\Query;


class SqlExpression
{
    /**
     * Используется в методе bind() для игнорирования именованных меток-заменителей в SQL шаблонизаторе
     */
    const BIND_SKIP = INF; //хотел использовать NAN, но с ним почему-то не работает, а с INF заработало /Ринат/

    const CAST_TOKEN = '::';
    const CAST_REPLACEMENT = "\x01^^\x02";

    const BLOCK_OPEN_TAG  = '{';
    const BLOCK_CLOSE_TAG = '}';

    const VALUE_PREFIX = ':';
    const FIELD_PREFIX = '@';

    const ENCODE_QUOTED = [
        "\x01" => "\x011\x02", //binary safe!
        "\x02" => "\x012\x02", //binary safe!
        ':'    => "\x013\x02",
        '@'    => "\x014\x02",
        '{'    => "\x015\x02",
        '}'    => "\x016\x02",
    ];

    const DECODE_QUOTED = [
        "\x011\x02" => "\x01",
        "\x012\x02" => "\x02",
        "\x013\x02" => ':',
        "\x014\x02" => '@',
        "\x015\x02" => '{',
        "\x016\x02" => '}',
    ];

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
     * Работает очень быстро, т.к. регулярные выражения для парсинга почти не используются
     * TODO добавить позиционные метки-заменители через знак вопроса ?
     *
     * Заменяет именованные метки-заменители в формате:
     *     :value
     *         Квотирует значение, массив возвращается в синтаксисе '{...}' //TODO заменить на более гибкий синтаксис ARRAY[...]
     *
     *     @field
     *         Квотирует идентификатор поля (синтаксис PostgreSQL и MySQL соответственно)
     *
     *     :values[]
     *         Квотирует каждый элемент массива как значение и склеивает их через запятую.
     *         Если передан НЕ массив, то работает аналогично метке-заменителю :value.
     *         Удобно использовать следующих конструкциях, примеры:
     *         IN(:values[]); VALUES(:values[]); COALESCE(:values[]); ROW(:values[]); ARRAY[:values[]]
     *
     *     @fields[]
     *         Квотирует каждый элемент массива как идентификатор поля и склеивает их через запятую.
     *         Если передан НЕ массив, то работает аналогично метке-заменителю @field.
     *         Удобно использовать следующих конструкциях, примеры:
     *         INSERT INTO t (@fields[]) ...; COALESCE(@fields[]); ROW(@fields[]); ARRAY[@fields[]]
     *
     * Обрабатывает условные блоки
     *       Условный блок -- это часть SQL запроса, с начальной и конечной фигурной скобкой: {...}
     *       Если в блоке есть хотябы одна метка заменитель, которой нет в ключах массива $values
     *       или значение ключа равно static::BIND_SKIP, то такой блок будет удалён со всеми вложенными блоками
     *       Пример SQL запроса:
     *       ```
     *       SELECT *,
     *              {EXISTS(SELECT r.col FROM r WHERE r.id = t.id AND r.exists_id = :exists_id) AS exists}
     *         FROM t
     *        WHERE TRUE
     *              {AND @col = :val}
     *              {AND id IN (:ids[])}
     *       {LIMIT :limit {OFFSET :offset}}
     *       ```
     *
     * @param string                    $sql
     * @param array                     $values Ассоциативный массив, где ключи -- это метки-заменители,
     *                                          а значения, это данные (любые типы), которые нужно заквотировать
     * @param \Cms\Db\Adapter\Quotation $quotation  Объект, отвечающий за квотирование
     *
     * @return \Cms\Db\Query\SqlExpression   Объект, в котором хранится готовое sql выражение
     *                                       Объект имеет метод __toString(), возвращающий готовое sql выражение
     *                                       Объект позволяет вставлять готовые куски SQL друг в друга без повторного квотирования
     * @throws \Exception
     * @link   http://php.net/manual/en/pdo.prepare.php
     */
    public static function bind(string $sql, array $values, \Cms\Db\Adapter\Quotation $quotation) : SqlExpression
    {
        $hasBlocks = is_int($offset = strpos($sql, static::BLOCK_OPEN_TAG))
                  && is_int(strpos($sql, static::BLOCK_CLOSE_TAG, $offset));

        //prevent PostgreSQL value cast like '... AS field::text'
        $castTokenReplacedCount = 0;
        $sql = str_replace(static::CAST_TOKEN, static::CAST_REPLACEMENT, $sql, $castTokenReplacedCount);

        $values = static::quote($values, $quotation);

        //квотированные данные могут содержать спецсимволы, которые не являются частью настоящих меток-заменителей и парных блоков
        //закодируем эти спецсимволы, чтобы корректно работали замены настоящих меток-заменителей и парсинг парных блоков
        $values = array_map(function($value){return strtr($value, static::ENCODE_QUOTED);}, $values);

        $sql = strtr($sql, $values);

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
     * Квотирует элементы переданного массива
     *
     * @param array                     $values
     * @param \Cms\Db\Adapter\Quotation $quotation
     *
     * @return array                    Возвращает ассоциативный массив, где каждый элемент является строкой с квотированными данными
     * @throws \Exception
     */
    protected static function quote(array &$values, \Cms\Db\Adapter\Quotation $quotation) : array {
        foreach ($values as $name => &$value) {
            if (! is_string($name)) {
                throw new \Exception('Ключи массива являются именованными метками-заменителями и они должны быть строками');
            }
            if (strlen($name) > 1) {
                if (strpos($name, static::CAST_TOKEN) !== false) {
                    throw new \Exception("Метка-заменитель '$name' не должна содержать подстроку '".static::CAST_TOKEN."'");
                }

                if ($value === static::BIND_SKIP) {
                    unset($values[$name]);
                    continue;
                }

                $isArray = substr($name, -2, 2) === '[]';

                if ($name{0} === static::VALUE_PREFIX) {
                    if ($isArray && is_array($value)) {
                        foreach ($value as $k => $v) $value[$k] = $quotation->quote($v);
                        $value = implode(', ', $value);
                        continue;
                    }
                    $value = $quotation->quote($value);
                    continue;

                }
                if ($name{0} === static::FIELD_PREFIX) {
                    if ($isArray && is_array($value)) {
                        foreach ($value as $k => $v) $value[$k] = $quotation->quoteField($v);
                        $value = implode(', ', $value);
                        continue;
                    }
                    $value = $quotation->quoteField($value);
                    continue;
                }
            }
            throw new \Exception("Формат метки-заменителя '$name' не поддерживается");
        }
        return $values;
    }

    /**
     * Разбивает строку на части по парным тегам, учитывая вложенность
     *
     * @param string $sql
     * @param string $openTag
     * @param string $closeTag
     *
     * @return array        Возвращает массив, где каждый элемент -- это массив из части строки и уровня вложенности
     * @throws \Exception
     */
    protected static function tokenize(string $sql, string $openTag, string $closeTag) : array
    {
        if ($openTag === $closeTag) {
            throw new \Exception("Парные теги '$openTag' и '$closeTag' не должны быть одинаковыми");
        }
        $level = 0;
        $tokens = array();
        $opens = explode($openTag, $sql);
        foreach ($opens as $open) {
            $closes = explode($closeTag, $open);
            $tokens[] = array($closes[0], ++$level);
            unset($closes[0]);
            foreach ($closes as $close) {
                $tokens[] = array($close, --$level);
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
     * @param array $tokens
     *
     * @return string
     */
    protected static function unTokenize(array $tokens) : string {
        $tokens = array_map(function($item){return $item[0];}, $tokens);
        return implode('', $tokens);
    }

    /**
     * Удаляет из массива элементы, в которых остались неиспользуемые метки-заменители
     *
     * @param array $tokens
     *
     * @return array    Возвращает массив с той же структурой, что и массив на входе
     */
    protected static function removeUnused(array &$tokens) : array
    {
        $return = array();
        $removeLevel = null;
        foreach ($tokens as $index => $token) {
            list ($str, $currentLevel) = $token;
            if ($removeLevel !== null && $removeLevel > $currentLevel) {
                for ($i = count($return); $i > 0; $i--) {
                    if ($return[$i - 1][1] < $removeLevel) break;
                    unset($return[$i - 1]);
                }
                $removeLevel = null;
            }
            if ($removeLevel === null && $currentLevel > 1 && static::getFirstPlaceholder($str) !== null) {
                $removeLevel = $currentLevel;
                continue;
            }
            if ($removeLevel === null) $return[] = $token;
        }
        return $return;
    }

    /**
     * Возвращает название первой найденной метки-заменителя в строке
     *
     * @param string $sql
     *
     * @return null|string  Возвращает null, если ничего не найдено
     */
    protected static function getFirstPlaceholder(string $sql) : ?string {
        //speed improves by strpos()
        foreach ([static::VALUE_PREFIX, static::FIELD_PREFIX] as $char) {
            $offset = strpos($sql, $char);
            if ($offset !== false) break;
        }
        if (! is_int($offset)) return null;

        $matches = [];
        preg_match('~[:@] [a-zA-Z_]+ [a-zA-Z_\d]* (?:\[\])? ~sxSX', $sql, $matches, null, $offset);
        if (count($matches) > 0) {
            return $matches[0];
        }
        return null;
    }

}
