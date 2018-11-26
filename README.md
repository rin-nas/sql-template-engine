# SQL template engine (шаблонизатор)

Работает очень быстро, т.к. регулярные выражения для парсинга почти не используются.

## Заменяет именованные метки-заменители

**`:value`**
    Квотирует значение, массив возвращается в синтаксисе `{...}`. TODO заменить на более гибкий синтаксис `ARRAY[...]`

**`@field`**
    Квотирует идентификатор поля (синтаксис PostgreSQL и MySQL соответственно)

**`:values[]`**
    Квотирует каждый элемент массива как значение и склеивает их через запятую.
    Если передан НЕ массив, то работает аналогично метке-заменителю `:value`.
    Удобно использовать следующих конструкциях, примеры:
    `IN(:values[]); VALUES(:values[]); COALESCE(:values[]); ROW(:values[]); ARRAY[:values[]]`

**`@fields[]`**
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
