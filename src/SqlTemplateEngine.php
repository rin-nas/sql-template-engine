<?php

namespace rinnas\SqlTemplateEngine;

use rinnas\SqlTemplateEngine\Quotation\IQuotation;

class SqlTemplateEngine
{
    /** @var \rinnas\SqlTemplateEngine\Quotation\IQuotation  */
    private $quotation;

    /**
     * SqlTemplateEngine constructor.
     *
     * @param \rinnas\SqlTemplateEngine\Quotation\IQuotation $quotation
     */
    public function __construct(IQuotation $quotation)
    {
        $this->quotation = $quotation;
    }

    /**
     * Шаблонизатор SQL
     * Документация: https://github.com/rin-nas/sql-template-engine/edit/master/README.md
     *
     * @param string $sql          Шаблон SQL запроса с необязательными метками-заменителями и условными блоками
     * @param array  $placeholders Ассоциативный массив, где ключи — это метки-заменители,
     *                             а значения, это данные (любые типы), которые нужно заквотировать
     *
     * @return \rinnas\SqlTemplateEngine\SqlExpression
     * @throws \Exception
     */
    public function bind(string $sql, array $placeholders): SqlExpression
    {
        return SqlExpression::bind($sql, $placeholders, $this->quotation);
    }

    /**
     * Делает bind() для каждого элемента переданного массива
     *
     * @param string $sql        Шаблон SQL запроса с метками-заменителями
     * @param array  $values     Ассоциативный массив
     *                           Для строковых ключей массива в SQL шаблоне зарезервирована метка-заменитель @key
     *                           Для значений массива в SQL шаблоне зарезервированы метки-заменители:
     *                           :key, :row, :row[], :value, :value[]
     *
     * @return \rinnas\SqlTemplateEngine\SqlExpression[]
     * @throws \Exception
     */
    public function bindEach(string $sql, array $values): array
    {
        return SqlExpression::bindEach($sql, $values, $this->quotation);
    }
}