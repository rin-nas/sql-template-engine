# SQL template engine (шаблонизатор)

**SQL шаблонизатор** — это инструмент, облегчающий конструирование [SQL](https://ru.wikipedia.org/wiki/SQL) запросов. В SQL шаблон вместо меток-заменителей (плейсхолдеров) подставляются настоящие данные (строки, числа, булевы, null, массивы). Важно, что при подстановке данных в шаблон они квотируются, чтобы недопустить [SQL инъекций](https://ru.wikipedia.org/wiki/%D0%92%D0%BD%D0%B5%D0%B4%D1%80%D0%B5%D0%BD%D0%B8%D0%B5_SQL-%D0%BA%D0%BE%D0%B4%D0%B0). Далее готовый SQL запрос выполнятся в СУБД.

Этот SQL шаблонизатор может применяться вместо [ORM](https://ru.wikipedia.org/wiki/ORM) и [Query Builder](https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/query-builder.html). См. [таблицу сравнения](https://github.com/rin-nas/articles/blob/master/sql_template_engine_vs_orm_or_qb.md).

Он работает очень быстро, т.к. [регулярные выражения](https://ru.wikipedia.org/wiki/%D0%A0%D0%B5%D0%B3%D1%83%D0%BB%D1%8F%D1%80%D0%BD%D1%8B%D0%B5_%D0%B2%D1%8B%D1%80%D0%B0%D0%B6%D0%B5%D0%BD%D0%B8%D1%8F) для синтаксического анализа (парсинга) SQL почти не используются.

## Заменяет именованные метки-заменители

1. **`:value`**
    Квотирует значение, массив возвращается в синтаксисе `{...}`. TODO заменить на более гибкий синтаксис `ARRAY[...]`
1. **`@field`**
    Квотирует идентификатор поля (синтаксис PostgreSQL и MySQL соответственно)

1. **`:values[]`**
    Квотирует каждый элемент массива как значение и склеивает их через запятую.
    Если передан НЕ массив, то работает аналогично метке-заменителю `:value`.
    Удобно использовать следующих конструкциях, примеры:
    `IN(:values[]); VALUES(:values[]); COALESCE(:values[]); ROW(:values[]); ARRAY[:values[]]`

1. **`@fields[]`**
    Квотирует каждый элемент массива как идентификатор поля и склеивает их через запятую.
    Если передан НЕ массив, то работает аналогично метке-заменителю `@field`.
    Удобно использовать следующих конструкциях, примеры:
    `INSERT INTO t (@fields[]) ...; COALESCE(@fields[]); ROW(@fields[]); ARRAY[@fields[]]`

## Обрабатывает условные блоки

Условный блок — это часть SQL запроса, с начальной и конечной фигурной скобкой: `{...}`
Если в блоке есть хотябы одна метка заменитель, которой нет в ключах массива меток-заменителей
или значение ключа равно `static::BIND_SKIP`, то такой блок будет удалён со всеми вложенными блоками
Пример SQL запроса:
      
```sql
SELECT *,
       {EXISTS(SELECT r.col FROM r WHERE r.id = t.id AND r.exists_id = :exists_id) AS exists}
  FROM t
 WHERE TRUE
       {AND @col = :val}
       {AND id IN (:ids[])}
{LIMIT :limit {OFFSET :offset}}
```
