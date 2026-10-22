<?php

declare(strict_types=1);

namespace Phycode\Browser;

use Phycode\Support\Json;

final class PageScripts
{
    public const DESCRIBE = '(function () {
        var navigation = performance.getEntriesByType("navigation")[0];
        return { url: location.href, title: document.title, status: navigation ? navigation.responseStatus : 0, text: document.body ? document.body.innerText : "" };
    })()';

    public const POINT = '(function (selector) {
        try {
            var element = document.querySelector(selector);
            if (!element) { return { error: "no element matches " + selector }; }
            element.scrollIntoView({ block: "center", inline: "center" });
            var box = element.getBoundingClientRect();
            if (box.width === 0 || box.height === 0) { return { error: "the element has no size on the page, so it is hidden" }; }
            var x = box.left + box.width / 2;
            var y = box.top + box.height / 2;
            var top = document.elementFromPoint(x, y);
            if (top && top !== element && !element.contains(top) && !top.contains(element)) {
                return { error: "the element is covered by <" + top.tagName.toLowerCase() + ">" };
            }
            return { x: x, y: y };
        } catch (failure) {
            return { error: "invalid selector " + selector + ": " + failure.message };
        }
    })';

    public const FOCUS = '(function (selector) {
        try {
            var element = document.querySelector(selector);
            if (!element) { return { error: "no element matches " + selector }; }
            var refused = ["checkbox", "radio", "button", "submit", "reset", "file", "image", "range", "color", "hidden"];
            var takesText = element.isContentEditable || element.tagName === "TEXTAREA" || (element.tagName === "INPUT" && refused.indexOf(element.type) < 0);
            if (!takesText) { return { error: "the element does not take text; click it instead" }; }
            element.scrollIntoView({ block: "center", inline: "center" });
            element.focus();
            if (document.activeElement !== element) { return { error: "the element cannot be focused" }; }
            if (typeof element.select === "function") { element.select(); }
            else { var range = document.createRange(); range.selectNodeContents(element); var selection = getSelection(); selection.removeAllRanges(); selection.addRange(range); }
            return { ok: true };
        } catch (failure) {
            return { error: "invalid selector " + selector + ": " + failure.message };
        }
    })';

    public static function call(string $function, string $argument): string
    {
        return $function . '(' . Json::encode($argument) . ')';
    }
}
