<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;

/**
 * A {@see SettingChecker} whose deployment configuration is a list of locked paths per scope, set
 * with {@see givenLocked()}, and which records every scope it was asked about.
 *
 * Mirrors the real checker's one rule that matters to its callers: a path locked at default scope
 * is locked at every scope.
 */
final class FakeSettingChecker extends SettingChecker
{
    /**
     * Locked `<path>|<scope>|<code>` keys.
     *
     * @var array<string,true>
     */
    private array $locked = [];

    /**
     * Every `[path, scope, code]` asked about, in order.
     *
     * @var list<array{string,string,string|null}>
     */
    private array $questions = [];

    public function __construct()
    {
    }

    /**
     * Lock a path at a scope, as `config:set --lock-env` would.
     *
     * @param string $path
     * @param string $scope
     * @param string|null $scopeCode
     * @return self
     */
    public function givenLocked(string $path, string $scope = 'default', ?string $scopeCode = null): self
    {
        $this->locked[$this->key($path, $scope, $scopeCode)] = true;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function isReadOnly($path, $scope, $scopeCode = null)
    {
        $this->questions[] = [$path, $scope, $scopeCode];

        return isset($this->locked[$this->key($path, $scope, $scopeCode)])
            || isset($this->locked[$this->key($path, 'default', null)]);
    }

    /**
     * @return list<array{string,string,string|null}>
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    private function key(string $path, string $scope, ?string $scopeCode): string
    {
        return $path . '|' . $scope . '|' . (string) $scopeCode;
    }
}
