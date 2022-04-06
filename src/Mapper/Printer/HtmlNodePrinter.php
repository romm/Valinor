<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Printer;

use BackedEnum;
use CuyZ\Valinor\Mapper\Tree\Message\Formatter\MessageFormatter;
use CuyZ\Valinor\Mapper\Tree\Message\NodeMessage;
use CuyZ\Valinor\Mapper\Tree\Node;
use CuyZ\Valinor\Type\ScalarType;
use DateTimeInterface;
use Stringable;
use UnitEnum;

use function array_map;
use function gettype;
use function htmlentities;
use function implode;
use function is_array;
use function is_bool;
use function is_object;
use function is_string;
use function strtolower;
use function usort;

// @todo rename Formatter?
final class HtmlNodePrinter
{
    private MessageFormatter $messageFormatter;

    public function withMessageFormatter(MessageFormatter $formatter): self
    {
        $clone = clone $this;
        $clone->messageFormatter = $formatter;

        return $clone;
    }

    public function toHtml(Node $node): string
    {
        return '<div class="vlnr-tree">' . $this->nodeToHtml($node) . '</div>';
    }

    private function nodeToHtml(Node $node): string
    {
        $summary = $this->summary($node);
        $messages = $this->messages($node);
        $valid = $node->isValid() ? 'valid' : 'invalid';

        if ($node->isLeaf()) {
            return <<<HTML
            <div class="vlnr-details vlnr-$valid">
                <div class="vlnr-summary">$summary</div>
                $messages
            </div>
            HTML;
        }

        $children = $this->children($node);

        return <<<HTML
        <details class="vlnr-details vlnr-$valid" open>
            <summary class="vlnr-summary">$summary</summary>
            $messages
            $children
        </details>
        HTML;
    }

    private function summary(Node $node): string
    {
        if ($node->isRoot()) {
            return '&nbsp;';
        }

        $summary = '<span class="vlnr-node-name">' . $node->name() . '</span>';

        if ($node->isValid() && $node->type() instanceof ScalarType) {
            $type = $this->rawType($node->value());
            $value = $this->toString($node->value());

            $summary .= '<span class="vlnr-value vlnr-type-' . $type . '">' . $value . '</span>';
        }

        return $summary;
    }

    private function messages(Node $node): string
    {
        $messages = $node->messages();

        if (count($messages) === 0) {
            return '';
        }

        $html = implode(PHP_EOL, array_map(
            function (NodeMessage $message) {
                $class = 'vlnr-message';

                if ($message->isError()) {
                    $class .= ' vlnr-message-error';
                }

                $text = isset($this->messageFormatter)
                    ? $this->messageFormatter->format($message)
                    : (string)$message;

                return '<li class="' . $class . '">' . htmlentities($text) . '</li>';
            },
            $messages
        ));

        return '<ul class="vlnr-messages">' . $html . '</ul>';
    }

    private function children(Node $node): string
    {
        $children = $this->sortChildren($node);

        if (count($children) === 0) {
            return '';
        }

        $html = implode(PHP_EOL, array_map(
            fn (Node $child) => '<li>' . $this->nodeToHtml($child) . '</li>',
            $children
        ));

        return "<ul>$html</ul>";
    }

    /**
     * @param mixed $value
     */
    private function toString($value): string
    {
        if (is_string($value)) {
            return htmlentities($value);
        }

        if (is_bool($value)) {
            return $value === true ? 'true' : 'false';
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof Stringable) {
            return (string)$value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return 'object';
        }

        return (string)$value; // @phpstan-ignore-line
    }

    /**
     * @return Node[]
     */
    private function sortChildren(Node $node): array
    {
        $children = $node->children();

        usort($children, static function (Node $childA, Node $childB) {
            if ($childA->isLeaf() === $childB->isLeaf()) {
                return 0;
            }

            return $childA->isLeaf() ? -1 : 1;
        });

        return $children;
    }

    /**
     * @param mixed $value
     */
    private function rawType($value): string
    {
        $type = gettype($value);

        switch ($type) {
            case 'string':
            case 'array':
            case 'object':
            case 'null':
                return strtolower($type);
            case 'boolean':
                return 'bool';
            case 'integer':
                return 'int';
            case 'double':
                return 'float';
            default:
                return 'unknown';
        }
    }
}
