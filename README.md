# SQL template engine (шаблонизатор)

**SQL шаблонизатор** — это инструмент, облегчающий конструирование [SQL](https://ru.wikipedia.org/wiki/SQL) запросов. В SQL шаблон вместо меток-заменителей (плейсхолдеров) подставляются настоящие данные (строки, числа, булевы, null, массивы, объекты). Важно, что при подстановке данных в шаблон они квотируются, чтобы недопустить [SQL инъекций](https://ru.wikipedia.org/wiki/%D0%92%D0%BD%D0%B5%D0%B4%D1%80%D0%B5%D0%BD%D0%B8%D0%B5_SQL-%D0%BA%D0%BE%D0%B4%D0%B0). Далее готовый SQL запрос уже можно выполнить в СУБД (PostgreSQL, MySQL, ClickHouse).

Этот SQL шаблонизатор может применяться вместо [ORM](https://ru.wikipedia.org/wiki/ORM) и [Query Builder](https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/query-builder.html). См. [таблицу сравнения](https://github.com/rin-nas/articles/blob/master/sql_template_engine_vs_orm_or_qb.md).

Он работает очень быстро, т.к. [регулярные выражения](https://ru.wikipedia.org/wiki/%D0%A0%D0%B5%D0%B3%D1%83%D0%BB%D1%8F%D1%80%D0%BD%D1%8B%D0%B5_%D0%B2%D1%8B%D1%80%D0%B0%D0%B6%D0%B5%D0%BD%D0%B8%D1%8F) для синтаксического анализа (парсинга) SQL почти не используются.

## Реализация

Шаблонизатор представляет собой один PHP файл [`SqlTemplate.php`](https://github.com/rin-nas/sql-template-engine/blob/master/SqlExpression.php), в котором всего 1 публичный метод:

```php
/**
 * Шаблонизатор SQL
 * Документация: https://github.com/rin-nas/sql-template-engine/edit/master/README.md
 *
 * @param string $sql            Шаблон SQL запроса с необязательными метками-заменителями и условными блоками
 * @param array  $placeholders   Ассоциативный массив, где ключи — это метки-заменители,
 *                               а значения, это данные (любые типы), которые нужно заквотировать
 * @param object $quotation      Объект, отвечающий за квотирование. Должен иметь методы quote() и quoteField()
 *
 * @return SqlExpression   Объект, в котором хранится готовое sql выражение
 *                         Объект имеет метод __toString(), возвращающий готовое sql выражение
 *                         Объект позволяет вставлять готовые куски SQL друг в друга без повторного квотирования
 * @throws \Exception
 * @link   http://php.net/manual/en/pdo.prepare.php
 */
public static function bind(string $sql, array $placeholders, $quotation) : SqlExpression
```

## Замена именованных меток-заменителей

1. **`:value`**
    Квотирует значение, массив возвращается в синтаксисе `{...}`.
    Возможные типы значений: `string`, `integer`, `float`, `boolean`, `null`, `array`, `SqlExpression`, `\DateTime`.
    
1. **`@field`**
    Квотирует идентификатор поля.
    Возможные типы значений: `string`, `array`, `SqlExpression`.
    
1. **`:values[]`**
    На входе ожидает массив и квотирует каждый его элемент как значение и склеивает их через запятую.
    Если передан НЕ массив, то работает аналогично метке-заменителю `:value`.
    Удобно использовать следующих конструкциях, примеры:
    `IN(:values[])`, `VALUES(:values[])`, `COALESCE(:values[])`, `ROW(:values[])`, `ARRAY[:values[]]`

1. **`@fields[]`**
    На входе ожидает массив и квотирует каждый его элемент как идентификатор поля и склеивает их через запятую.
    Если передан НЕ массив, то работает аналогично метке-заменителю `@field`.
    Удобно использовать следующих конструкциях, примеры:
    `INSERT INTO t (@fields[]) ...`, `COALESCE(@fields[])`, `ROW(@fields[])`, `ARRAY[@fields[]]`
    
Синтаксис меток-заменителей в терминах регулярных выражений [PCRE](http://pcre.org/): `~[:@?] [a-zA-Z_]+ [a-zA-Z_\d]* (?:\[\])? ~sxSX`

## Обработка условных блоков

Условный блок — это часть SQL запроса, с начальной и конечной фигурной скобкой: `{...}`
* Если в блоке есть хотябы одна метка-заменитель, которой нет в ключах массива меток-заменителей
или значение ключа равно `BIND_SKIP`, то такой блок будет удалён со всеми вложенными блоками.
* Если значение ключа равно `BIND_KEEP`, то блок будет сохранён (вложенные блоки независимы), а сама метка-заменитель удаляется.
Пример SQL запроса:
      
```sql
SELECT *
       {,EXISTS(SELECT r.col FROM r WHERE r.id = t.id AND r.exists_id = :exists_id) AS exists}
  FROM t
 WHERE TRUE
       {AND @col = :val}
       {AND id IN (:ids[])}
{LIMIT :limit {OFFSET :offset}}
```

## TODO
* изменить синтаксис квотирования массивов с `{...}` на более гибкий `ARRAY[...]`
* добавить позиционные метки-заменители через знак вопроса `?0`, `?1`, ...
* добавить пример с подзапросом и именем поля "name::json", который декодирует JSON
* добавить примеры вызова метода `bind()` с параметрами и результатом
