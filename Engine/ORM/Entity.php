<?php

declare(strict_types=1);

namespace Luxid\ORM;

use Luxid\Foundation\Application;

/**
 * Legacy validation base class.
 *
 * Provides declarative rules for entities that predate Rocket's attribute based
 * validation. New entities should use `Rocket\Attributes\Rules\*` instead.
 *
 * @package Luxid\ORM
 */
abstract class Entity
{
    public const RULE_REQUIRED = 'required';
    public const RULE_EMAIL = 'email';
    public const RULE_MIN = 'min';
    public const RULE_MAX = 'max';
    public const RULE_MATCH = 'match';
    public const RULE_UNIQUE = 'unique';

    /**
     * Validation errors keyed by attribute.
     *
     * @var array<string, list<string>>
     */
    public array $errors = [];

    /**
     * Declare the validation rules for this entity.
     *
     * @return array<string, list<string|array<string, mixed>>>
     */
    abstract public function rules(): array;

    /**
     * Assign an array of values onto matching properties.
     *
     * @param array<string, mixed> $data Values keyed by property name
     */
    public function loadData(array $data): self
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }

        return $this;
    }

    /**
     * Human readable labels keyed by attribute.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return [];
    }

    /**
     * Get the label for an attribute, falling back to its name.
     *
     * @param string $attribute Attribute name
     */
    public function getLabel(string $attribute): string
    {
        return $this->labels()[$attribute] ?? $attribute;
    }

    /**
     * Run every declared rule and collect the failures.
     *
     * @return bool True when the entity is valid
     */
    public function validate(): bool
    {
        $this->errors = [];

        foreach ($this->rules() as $attribute => $rules) {
            // Typed properties may be uninitialized; reading one directly throws.
            $value = $this->readAttribute($attribute);

            foreach ($rules as $rule) {
                $this->applyRule($attribute, $value, $rule);
            }
        }

        return $this->errors === [];
    }

    /**
     * Apply a single rule to an attribute.
     *
     * @param string                    $attribute Attribute name
     * @param mixed                     $value     Current value
     * @param string|array<string,mixed> $rule     Rule name, or rule name plus options
     */
    private function applyRule(string $attribute, mixed $value, string|array $rule): void
    {
        $name = is_string($rule) ? $rule : ($rule[0] ?? '');
        $options = is_array($rule) ? $rule : [];
        $length = is_scalar($value) ? strlen((string) $value) : 0;

        match ($name) {
            self::RULE_REQUIRED => $this->when(
                $value === null || $value === '' || $value === [],
                $attribute,
                self::RULE_REQUIRED
            ),
            self::RULE_EMAIL => $this->when(
                !is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false,
                $attribute,
                self::RULE_EMAIL
            ),
            self::RULE_MIN => $this->when(
                $length < (int) ($options['min'] ?? 0),
                $attribute,
                self::RULE_MIN,
                $options
            ),
            self::RULE_MAX => $this->when(
                $length > (int) ($options['max'] ?? PHP_INT_MAX),
                $attribute,
                self::RULE_MAX,
                $options
            ),
            self::RULE_MATCH => $this->validateMatch($attribute, $value, $options),
            self::RULE_UNIQUE => $this->validateUnique($attribute, $value, $options),
            default => null,
        };
    }

    /**
     * Fail when the value differs from another attribute on this entity.
     *
     * @param string               $attribute Attribute name
     * @param mixed                $value     Current value
     * @param array<string, mixed> $options   Rule options, expects `match`
     */
    private function validateMatch(string $attribute, mixed $value, array $options): void
    {
        $other = $options['match'] ?? null;

        if (!is_string($other)) {
            return;
        }

        if ($value !== $this->readAttribute($other)) {
            $this->addErrorForRule($attribute, self::RULE_MATCH, ['match' => $this->getLabel($other)]);
        }
    }

    /**
     * Fail when another row already holds this value.
     *
     * The column is validated against the target entity's own rules before it
     * reaches SQL, because identifiers cannot be bound as parameters.
     *
     * @param string               $attribute Attribute name
     * @param mixed                $value     Current value
     * @param array<string, mixed> $options   Rule options, expects `class` and optionally `attribute`
     */
    private function validateUnique(string $attribute, mixed $value, array $options): void
    {
        $class = $options['class'] ?? null;

        if (!is_string($class) || !class_exists($class)) {
            return;
        }

        $column = $options['attribute'] ?? $attribute;

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $column) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid unique column "%s"', $column));
        }

        $connection = Application::$app->db ?? null;

        if ($connection === null) {
            return;
        }

        $rows = $connection->query(
            sprintf('SELECT 1 FROM %s WHERE %s = :value LIMIT 1', $class::tableName(), $column),
            ['value' => $value]
        );

        if ($rows !== []) {
            $this->addErrorForRule($attribute, self::RULE_UNIQUE, ['field' => $this->getLabel($attribute)]);
        }
    }

    /**
     * Record an error when the condition holds.
     *
     * @param bool                 $failed    Whether the rule failed
     * @param string               $attribute Attribute name
     * @param string               $rule      Rule name
     * @param array<string, mixed> $params    Placeholder values for the message
     */
    private function when(bool $failed, string $attribute, string $rule, array $params = []): void
    {
        if ($failed) {
            $this->addErrorForRule($attribute, $rule, $params);
        }
    }

    /**
     * Read an attribute, tolerating uninitialized typed properties.
     *
     * @param string $attribute Attribute name
     */
    private function readAttribute(string $attribute): mixed
    {
        if (!property_exists($this, $attribute)) {
            return null;
        }

        return isset($this->{$attribute}) ? $this->{$attribute} : null;
    }

    /**
     * Add an error using the message template for a rule.
     *
     * @param string               $attribute Attribute name
     * @param string               $rule      Rule name
     * @param array<string, mixed> $params    Placeholder values for the message
     */
    private function addErrorForRule(string $attribute, string $rule, array $params = []): void
    {
        $message = $this->errorMessages()[$rule] ?? '';

        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $message = str_replace('{' . $key . '}', (string) $value, $message);
            }
        }

        $this->errors[$attribute][] = $message;
    }

    /**
     * Add an error message directly.
     *
     * @param string $attribute Attribute name
     * @param string $message   Error message
     */
    public function addError(string $attribute, string $message): self
    {
        $this->errors[$attribute][] = $message;

        return $this;
    }

    /**
     * Message templates keyed by rule name.
     *
     * @return array<string, string>
     */
    public function errorMessages(): array
    {
        return [
            self::RULE_REQUIRED => 'This field is required.',
            self::RULE_EMAIL => 'This field must be a valid email address.',
            self::RULE_MIN => 'Minimum length of this field must be {min}.',
            self::RULE_MAX => 'Maximum length of this field must be {max}.',
            self::RULE_MATCH => 'This field must be the same as {match}.',
            self::RULE_UNIQUE => 'Record with this {field} already exists.',
        ];
    }

    /**
     * Get the errors recorded for an attribute.
     *
     * @param string $attribute Attribute name
     *
     * @return list<string>
     */
    public function getErrors(string $attribute): array
    {
        return $this->errors[$attribute] ?? [];
    }

    /**
     * Check whether an attribute has any errors.
     *
     * @param string $attribute Attribute name
     */
    public function hasError(string $attribute): bool
    {
        return ($this->errors[$attribute] ?? []) !== [];
    }

    /**
     * Get the first error recorded for an attribute.
     *
     * @param string $attribute Attribute name
     */
    public function getFirstError(string $attribute): string
    {
        return $this->errors[$attribute][0] ?? '';
    }
}
