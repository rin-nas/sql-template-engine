# TODO
* добавить пример с подзапросом 
* добавить пример с именем поля, которое оканчивается на `:json` (например `"event_data::json"`), в этом случае строка с JSON автоматически декодируется
* исправить ошибку обработки условных блоков в строках, внутри которых есть фигурные скобки, например `'{"type": "home"}'::jsonb`
* посмотреть на идеи шаблонизатора [sql-template-strings](https://www.npmjs.com/package/sql-template-strings), [Blitz](https://habr.com/ru/post/93720/), [Thesis](https://github.com/thesisphp/thesis) (см. [видео](https://youtu.be/dlLOxg4FI-s?t=21755))
