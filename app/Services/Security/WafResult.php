<?php

namespace App\Services\Security;

use App\Models\WafRule;

/**
 * Immutable value object describing a WAF rule evaluation outcome.
 *
 * `matched` is true when the engine found a rule that fired.
 * `rule` references the model (or array representation) that matched.
 * `action` is the action to take (allow|block|challenge|log|rate_limit).
 * `evidence` is the substring / match that triggered the rule — useful
 * for logging and the API "test" endpoint.
 */
class WafResult
{
    public function __construct(
        public readonly bool $matched,
        public readonly ?WafRule $rule = null,
        public readonly string $action = WafRule::ACTION_LOG,
        public readonly ?string $evidence = null,
        public readonly array $evaluated = [],
    ) {}

    public static function allow(int $evaluated = 0): self
    {
        return new self(false, null, WafRule::ACTION_ALLOW, null, $evaluated > 0 ? ['count' => $evaluated] : []);
    }

    public static function match(WafRule $rule, ?string $evidence = null, int $evaluated = 0): self
    {
        return new self(true, $rule, $rule->action, $evidence, ['count' => $evaluated, 'rule' => $rule->slug]);
    }

    public function isBlocking(): bool
    {
        return $this->matched && in_array($this->action, [WafRule::ACTION_BLOCK, WafRule::ACTION_CHALLENGE], true);
    }

    public function isLoggable(): bool
    {
        return $this->matched; // we always log matched rules
    }

    public function toArray(): array
    {
        return [
            'matched'   => $this->matched,
            'action'    => $this->action,
            'evidence'  => $this->evidence,
            'evaluated' => $this->evaluated,
            'rule'      => $this->rule ? [
                'id'       => $this->rule->id,
                'uuid'     => $this->rule->uuid,
                'slug'     => $this->rule->slug,
                'name'     => $this->rule->name,
                'type'     => $this->rule->type,
                'action'   => $this->rule->action,
                'priority' => $this->rule->priority,
            ] : null,
        ];
    }
}
