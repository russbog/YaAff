<?php

/**
 * Pure permission resolution for the RBAC layer (Phase 10).
 *
 * Permissions are plain dot-namespaced strings, e.g. "offers.view",
 * "offers.manage", "users.manage". They are entirely data-driven: roles store a
 * list of granted permissions and this class decides whether a granted set
 * satisfies a required permission. No I/O, fully testable.
 *
 * Wildcards:
 *   "*"            super-permission, grants everything
 *   "offers.*"     grants every action under the "offers" namespace
 *   "*.view"       grants the "view" action across every namespace
 *
 * The needed permission may itself be "type.*" to ask "can the user do anything
 * with this type?" — useful for menu visibility.
 */
class AccessControl
{
    /**
     * Whether a set of granted permissions satisfies the needed permission.
     *
     * @param array<int,string> $granted
     */
    public static function permits(array $granted, string $needed): bool
    {
        $needed = trim($needed);
        if ($needed === '') {
            return true;
        }
        foreach ($granted as $rule) {
            if (self::ruleMatches((string)$rule, $needed)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any of the needed permissions is satisfied (OR semantics).
     *
     * @param array<int,string> $granted
     * @param array<int,string> $anyOf
     */
    public static function permitsAny(array $granted, array $anyOf): bool
    {
        foreach ($anyOf as $needed) {
            if (self::permits($granted, (string)$needed)) {
                return true;
            }
        }
        return false;
    }

    private static function ruleMatches(string $rule, string $needed): bool
    {
        $rule = trim($rule);
        if ($rule === '') {
            return false;
        }
        if ($rule === '*' || $rule === $needed) {
            return true;
        }

        $ruleParts = explode('.', $rule);
        $needParts = explode('.', $needed);

        // "offers.*" must also satisfy a request for the namespace itself ("offers").
        if (end($ruleParts) === '*' && count($ruleParts) === count($needParts) + 1) {
            array_pop($ruleParts);
            return $ruleParts === array_slice($needParts, 0, count($ruleParts));
        }

        if (count($ruleParts) !== count($needParts)) {
            return false;
        }
        foreach ($ruleParts as $i => $segment) {
            if ($segment !== '*' && $segment !== $needParts[$i]) {
                return false;
            }
        }
        return true;
    }
}
