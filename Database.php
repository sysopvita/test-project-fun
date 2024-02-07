<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    const SPECS_AND_BLOCKS_REGEX = '/(?:\?[^(){}\s]?)|(?:{[^}]*})/m';
    const CONDITIONAL_BLOCK_REGEX = '/^{(?\'conditional_block\'.*)}$/m';

    /**
     * @var mysqli
     */
    private mysqli $mysqli;

    /**
     * @var bool
     */
    private bool $skipConditionalBlock;

    /**
     * @param mysqli $mysqli
     */
    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @param string $query
     * @param array $args
     * @return string
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        return preg_replace_callback(self::SPECS_AND_BLOCKS_REGEX, function($match) use (&$args) {
            if (!$args) {
                throw new Exception('Не совпадает количество спецификаторов и параметров');
            }

            $arg = array_shift($args);
            $spec = Specificators::tryFrom(current($match));

            if ($spec) {
                return $this->convert($arg, $spec);
            }

            $this->skipConditionalBlock = false;
            $blockContent = $this->buildQuery(
                $this->getConditionalBlockContent(current($match)),
                array_merge([$arg], $args)
            );

            return $this->skipConditionalBlock ? '' : $blockContent;
        }, $query);
    }

    /**
     * @return SpecDefinitions
     */
    public function skip()
    {
        return SpecDefinitions::SKIP;
    }

    /**
     * @param $arg
     * @param Specificators $spec
     * @param SpecDefinitions|null $spec_definition
     * @return mixed
     * @throws Exception
     */
    private function convert($arg, Specificators $spec, ?SpecDefinitions $spec_definition = null): mixed
    {
        if ($arg === SpecDefinitions::SKIP) {
            $this->skipConditionalBlock = true;
            return '';
        }

        if (in_array($spec, [Specificators::ARRAY_SPEC, Specificators::IDENTIFIER_SPEC])) {
            return $this->convertArrays($arg, $spec);
        }

        if (!$this->validateType($arg)) {
            throw new Exception('Неверный тип аргумента');
        }

        if ($spec === Specificators::INT_SPEC && settype($arg, 'integer')) {
            return $arg;
        }

        if ($spec === Specificators::FLOAT_SPEC && settype($arg, 'double')) {
            return $arg;
        }

        if ($spec === Specificators::COMMON_SPEC) {
            return $this->convertCommon($arg, $spec_definition);
        }

        throw new Exception('Не удалось преобразовать аргумент в требуемый тип');
    }

    /**
     * @param string $str
     * @return string
     * @throws Exception
     */
    private function getConditionalBlockContent(string $str): string
    {
        $result = preg_match(self::CONDITIONAL_BLOCK_REGEX, $str, $matches);
        if (!$result) {
            throw new Exception('Неизвестный спецификатор');
        }

        $cb = $matches['conditional_block'] ?? '';
        if (!$cb) {
            throw new Exception('Пустой условный блок');
        }

        return $cb;
    }

    /**
     * @param $arg
     * @param Specificators $spec
     * @return string
     * @throws Exception
     */
    private function convertArrays($arg, Specificators $spec): string
    {
        if (!is_array($arg)) {
            $arg = [$arg];
        }
        array_walk($arg, function (&$v, $k) use ($spec, $arg) {
            $v = $this->convert(
                $v,
                Specificators::COMMON_SPEC,
                $spec === Specificators::IDENTIFIER_SPEC
                    ? SpecDefinitions::IDENTIFIER
                    : null
            );

            if (!array_is_list($arg)) {
                $key = $this->convert($k, Specificators::COMMON_SPEC, SpecDefinitions::IDENTIFIER);
                $v = "$key = $v";
            }
        });
        return implode(', ', $arg);
    }

    /**
     * @param $arg
     * @param SpecDefinitions|null $spec_definition
     * @return string
     */
    private function convertCommon($arg, ?SpecDefinitions $spec_definition = null): string
    {
        if (is_null($arg)) {
            return 'NULL';
        }

        if (is_bool($arg)) {
            $arg = (int) $arg;
        }

        if (is_string($arg)) {
            $arg = str_replace('\'', '\\\'', $arg);
            $arg = match ($spec_definition) {
                SpecDefinitions::IDENTIFIER => "`$arg`",
                default => "'$arg'",
            };
        }

        return $arg;
    }

    /**
     * @param $arg
     * @return bool
     */
    private function validateType($arg): bool
    {
        return in_array(gettype($arg), [
            'boolean',
            'integer',
            'string',
            'double',
            'NULL',
        ]);
    }
}
