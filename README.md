# SQL template engine (SQL шаблонизатор)

**SQL шаблонизатор** — это инструмент, облегчающий конструирование [SQL](https://ru.wikipedia.org/wiki/SQL) запросов. В SQL шаблон вместо меток-заменителей (placeholders) подставляются настоящие данные (строки, числа, булевы, null, массивы, объекты). Важно, что при подстановке данных в шаблон они квотируются, чтобы недопустить [SQL инъекций](https://ru.wikipedia.org/wiki/%D0%92%D0%BD%D0%B5%D0%B4%D1%80%D0%B5%D0%BD%D0%B8%D0%B5_SQL-%D0%BA%D0%BE%D0%B4%D0%B0). Далее готовый SQL запрос уже можно выполнить в СУБД (PostgreSQL, MySQL, ClickHouse).

Этот SQL шаблонизатор может применяться вместо [ORM](https://ru.wikipedia.org/wiki/ORM) и [Query Builder](https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/query-builder.html). См. [таблицу сравнения](/sql_template_engine_vs_orm_vs_qb.md).

Он работает очень быстро, т.к. [регулярные выражения](https://ru.wikipedia.org/wiki/%D0%A0%D0%B5%D0%B3%D1%83%D0%BB%D1%8F%D1%80%D0%BD%D1%8B%D0%B5_%D0%B2%D1%8B%D1%80%D0%B0%D0%B6%D0%B5%D0%BD%D0%B8%D1%8F) для синтаксического анализа (парсинга) SQL почти не используются.

## Реализация

Шаблонизатор представляет собой один PHP файл [`SqlTemplate.php`](https://github.com/rin-nas/sql-template-engine/blob/master/SqlExpression.php), в котором всего 2 публичных метода.

```php
/**
 * Шаблонизатор SQL
 * Документация: https://github.com/rin-nas/sql-template-engine/edit/master/README.md
 *
 * @param string $sql          Шаблон SQL запроса с необязательными метками-заменителями и условными блоками
 * @param array  $placeholders Ассоциативный массив, где ключи — это метки-заменители,
 *                             а значения, это данные (любые типы), которые нужно заквотировать
 * @param object $quotation    Объект, отвечающий за квотирование. Должен иметь методы:
 *                             * quote() -- для квотирования данных
 *                             * quoteField() -- для квотирования идентификатора объекта БД (название таблицы, колонки, процедуры и т.п.)
 *
 * @return SqlExpression   Объект, в котором хранится готовое sql выражение
 *                         Объект имеет метод __toString(), возвращающий готовое sql выражение
 *                         Объект позволяет вставлять готовые куски SQL друг в друга без повторного квотирования
 * @throws \Exception
 * @link   http://php.net/manual/en/pdo.prepare.php
 */
public static function bind(string $sql, array $placeholders, $quotation) : SqlExpression
```

```php
/**
 * Делает bind() для каждого элемента переданного массива
 *
 * @param string $sql        Шаблон SQL запроса с метками-заменителями
 * @param array  $values     Ассоциативный массив
 *                           Для строковых ключей массива в SQL шаблоне зарезервирована метка-заменитель @key
 *                           Для значений массива в SQL шаблоне зарезервированы метки-заменители:
 *                           :key, :row, :row[], :value, :value[]
 * @param object $quotation  Объект, отвечающий за квотирование. Должен иметь методы quote() и quoteField()
 *
 * @return SqlExpression[]
 * @throws \Exception
 */
public static function bindEach(string $sql, array $values, $quotation) : array
```

## Замена именованных меток-заменителей

Синтаксис меток-заменителей в терминах регулярных выражений [PCRE](http://pcre.org/): 
`~ [:@?] [a-zA-Z_]+ [a-zA-Z_\d]* (?:\[\])? ~sxSX`

1. **`:value`**, где `value` — это произвольное название.
   Квотирует значение, массив возвращается в синтаксисе `{…}`.
   Возможные типы значений: `string`, `integer`, `float`, `boolean`, `null`, `array`, `SqlExpression`, `\DateTime`.

2. **`@field`**, где `field` — это произвольное название.
   Квотирует идентификатор объекта БД (название таблицы, колонки, процедуры и т.п.).
   Возможные типы значений: `string`, `array`, `SqlExpression`.

3. **`:values[]`**
   На входе ожидает массив и квотирует каждый его элемент как значение и склеивает их через запятую.
   Если передан НЕ массив, то работает аналогично метке-заменителю `:value`.
   Удобно использовать следующих конструкциях, примеры:
   `IN(:values[])`, `VALUES(:values[])`, `COALESCE(:values[])`, `ROW(:values[])`, `ARRAY[:values[]]`

4. **`@fields[]`**
   На входе ожидает массив и квотирует каждый его элемент как идентификатор объекта БД (название таблицы, колонки, процедуры и т.п.) и склеивает их через запятую.
   Если передан НЕ массив, то работает аналогично метке-заменителю `@field`.
   Удобно использовать следующих конструкциях, примеры:
   `INSERT INTO t (@fields[]) …`, `COALESCE(@fields[])`, `ROW(@fields[])`, `ARRAY[@fields[]]`

5. **`?field`**, где `field` — это произвольное название. Эта метка-заменитель называется _условной_, т.к. используется только внури условных блоков (может стоять там в любой позиции).
   На входе ожидает логическое значение. Нелогическое значение приводит к логическому по правилам PHP (`if ($value) ...`).
   Если `true`, то условный блок, в котором находится эта метка-заменитель, и вложенные блоки будут сохранены, а сама метка-заменитель будет удалена.
   Если `false`, то условный блок, в котором находится эта метка-заменитель, и вложенные блоки будут удалены.
   
## Обработка условных блоков

Условный блок — это часть SQL запроса между фигурными скобками `{` и `}`.

1. Блок будет удалён (со всеми вложенными блоками), если в нём есть хотябы одна метка-заменитель, название которой нет в ключах переданного массива меток-заменителей _или_ её значение равно `BIND_SKIP`.
1. Иначе блок будет сохранён (вложенные блоки независимы). При этом:
   * Если значение метки-заменителя НЕ равно `BIND_KEEP`, то метка будет заменена на квотированное значение.
   * Если значение метки-заменителя равно `BIND_KEEP`, то метка будет удалена. Фактически `BIND_KEEP` удобно использовать только для условных меток-заменителей.

Пример SQL запроса, который можно настроить для получения табличных данных _или_ итогового количества записей:
      
```sql
SELECT {?total COUNT(*)}
       {?fields   
           t.*
           {, EXISTS(SELECT r.col FROM r WHERE r.id = t.id AND r.exists_id = :exists_id) AS exists}
       }
  FROM t
 WHERE TRUE
       {AND @col = :val}
       {AND t.id IN (:ids[])}
{?fields
    ORDER BY t.name
    {LIMIT :limit}
    {OFFSET :offset}
}
```

## Пример использования 1

```php
$data = [
    [
        'person_id' => null,
        'created_at' => (new \DateTime()),
        'recipient' => 'test1@mail.ru',
        'scores' => 10,
        'reason' => 'Невозможно доставить сообщение',
    ],
    [
        'person_id' => 555,
        'created_at' => '2020-09-10 10:30:00',
        'recipient' => 'test2@mail.ru',
        'scores' => 90,
        'reason' => 'Email не существует',
    ],
    //...
];

$sql = $this->db->bind(
   'INSERT INTO @table (@fields[])
    VALUES :rows[]
    ON CONFLICT (lower(@recipient)) 
    DO UPDATE SET 
        @scores = LEAST(@table.@scores + EXCLUDED.@scores, :maxScores), 
        @reason = EXCLUDED.@reason',
    [
        '@table' => self::_TABLE,
        '@fields[]' => array_keys(reset($data)),
        '@recipient' => self::RECIPIENT,
        '@scores' => self::SCORES,
        '@reason' => self::REASON,
        ':rows[]' => $this->db->bindEach('(:row[])', $data),
        ':maxScores' => 100,
    ]
);

$this->db->query($sql);

```
Результат в `$sql` (код отформатирован для удобства чтения):
```sql
INSERT INTO "user_email_blacklist" ("person_id", "created_at", "recipient", "scores", "reason")
VALUES 
    (NULL, '2020-09-13T11:08:40.597+03:00'::timestamptz, 'test1@mail.ru', 10, 'Невозможно доставить сообщение'), 
    (555, '2020-09-10 10:30:00', 'test2@mail.ru', 90, 'Email не существует')
ON CONFLICT (lower("recipient")) 
DO UPDATE SET 
    "scores" = LEAST("user_email_blacklist"."scores" + EXCLUDED."scores", 100), 
    "reason" = EXCLUDED."reason"
```

## Пример использования 2

```php
/**
 *
 * @param mixed[] $filter
 * @param array   $fields
 * @param string  $sort
 * @param int     $limit
 * @param int     $offset
 * @param bool    $isCountTotal
 *
 * @return array[]|int
 * @throws \Rdw\X\Db\Exceptions\DbException
 * @throws \Exception
 */
public function getByFilter(
    array $filter = [],
    array $fields = [],
    string $sort = '',
    int $limit = 20,
    int $offset = 0,
    bool $isCountTotal = false
) {
    if (count($fields) > 0) {
        $this->selectAll = false;
    }
    $this->select = $fields;
    $this->return = [];

    $placeholders = [
        '?total'           => $isCountTotal,
        '?fields'          => ! $isCountTotal,
        '?has_subway'      => $this->fieldSelected(static::FIELD_HAS_SUBWAY),
        '?ids'             => count($filter['ids'] ?? []) > 0,
        '?not_ids'         => count($filter['not_ids'] ?? []) > 0,
        '?type_ids'        => count($filter['type_ids'] ?? []) > 0,
        '?query'           => strlen($filter['query'] ?? '') > 0,
        '?big_cities_only' => ($filter['big_cities_only'] ?? false),
        '?sort_name'       => $sort === self::SORT_NAME,
        '?sort_order_num'  => $sort === self::SORT_ORDER_NUMBER,
        '?sort_query_rank' => $sort === self::SORT_QUERY_RANK,
        '?country_ids'     => !empty($filter['country_ids'] ?? []),

        ':specify'         => in_array(static::FIELD_SPECIFY, $fields)
                                    ? $this->db->bind(self::getFieldSpecifySQL(), [])
                                    : Database::BIND_SKIP,
        ':ids[]'           => ($filter['ids']         ?? Database::BIND_SKIP),
        ':not_ids[]'       => ($filter['not_ids']     ?? Database::BIND_SKIP),
        ':type_ids[]'      => ($filter['type_ids']    ?? Database::BIND_SKIP),
        ':query'           => ($filter['query']       ?? Database::BIND_SKIP),
        ':country_ids[]'   => ($filter['country_ids'] ?? Database::BIND_SKIP),
        ':limit'           => $limit,
        ':offset'          => $offset,
    ];

    $field = static::FIELD_PAID_MODEL_AVAILABLE;
    if (($filter[$field] ?? null) !== null) {
        $placeholders['?filter_paid_model']   = true;
        $placeholders['?filter_pm_condition'] = !$filter[$field];
        $placeholders[':link_type_auto_bind'] = V3CityPublishers::LINK_TYPE_AUTO_BIND;
    }

    $sql = <<<'SQL'
{?query 
WITH
    normalize AS (
        SELECT ltrim(REGEXP_REPLACE(LOWER(:query::text), '[^а-яёa-z0-9]+', ' ', 'gi')) AS query
    ),
    vars AS (
        SELECT CONCAT('%', REPLACE(quote_like(trim(normalize.query)), ' ', '_%'), '%') AS query_like,
               CONCAT(
                   '(?<![а-яёa-z0-9])', 
                   REPLACE(quote_regexp(normalize.query), ' ', '(?:[^а-яёa-z0-9]+|$)')
               ) AS query_regexp
        FROM normalize
    )
}
SELECT
    {?total COUNT(*)}
    {?fields
        r.*,
        COALESCE(r.is_big_city, false) as is_big_city,
        {?has_subway EXISTS(SELECT 1 
                            FROM v3_metro AS ms
                            INNER JOIN v3_metro_branch AS mb ON mb.id = ms.metro_branch_id  
                            WHERE mb.region_id = r.id 
                            LIMIT 1) AS has_subway,}
        {:specify AS specify,}
        r.id
    }
FROM v3_region AS r
INNER JOIN v3_region_type AS rt ON r.region_type_id = rt.id
INNER JOIN v3_country AS c ON c.id = r.country_id
{?query , vars, normalize}
WHERE true
    AND r.city_id IS NOT NULL
    AND r.id != 1954 -- "Дальний Восток" исключается, т.к. для РФ это территория из нескольких субъектов федерации!
    AND r.region_type_id != 12
    {?type_ids        AND r.region_type_id IN (:type_ids[])}
    {?ids             AND r.id IN (:ids[])}
    {?not_ids         AND r.id NOT IN (:not_ids[])}
    {?big_cities_only AND r.is_big_city = true}
    {?filter_paid_model AND {?filter_pm_condition NOT }
      EXISTS(SELECT 1 FROM v3_city INNER JOIN v3_city_publisher cp ON v3_city.id = cp.city_id 
        WHERE r.city_id=v3_city.id AND cp.link_type=:link_type_auto_bind)
    }
    {?query
        AND length(rtrim(normalize.query)) > 0 -- для скорости
        AND lower(r.name) LIKE vars.query_like -- для скорости
        AND lower(r.name) ~* vars.query_regexp -- для точности
    }
    {?country_ids AND r.country_id IN (:country_ids[])}
{?fields
    {?sort_name              ORDER BY r.name, r.order_num}
    {?sort_order_num         ORDER BY r.order_num, NLEVEL(r.ltree_path), name}
    {?sort_query_rank ?query ORDER BY lower(r.name) LIKE RTRIM(normalize.query), LENGTH(r.name), r.name}
    LIMIT  :limit
    OFFSET :offset
}
SQL;

    $sql = $this->db->bind($sql, $placeholders);

    if ($isCountTotal) {
        return $this->db->fetchOne($sql);
    }
    return $this->db->fetchAll($sql);
}
```

## Замечания по безопасности

Не используйте метки-заменители и фигурные скобки `{` и `}` внутри комментариев `/*...*/` и `--...`! Шаблонизатор про комментарии ничего не знает.

Исходный шаблон запроса:
```sql
SELECT /* @col1, */ col2 FROM t
```
Если значение метки `@col1` будет ` */ `, то SQL запрос сломается. Более того, код становится небезопасным и открываются возможности для инъекции зловредного кода ([XSS](https://en.wikipedia.org/wiki/Cross-site_scripting)).

Сгенерированный SQL:
```sql
SELECT /* " */ ", */ col2 FROM t
```

## Ссылки по теме
* https://www.npmjs.com/package/sql-template-strings
