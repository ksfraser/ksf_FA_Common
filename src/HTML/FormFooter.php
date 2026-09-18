<?php
/**
 * FormFooter — Submit + Cancel button row builder for FA master summary tables.
 *
 * Renders the centred Submit / Cancel button pair that closes a master
 * summary table form. Both buttons are `type="submit"` with `formnovalidate`
 * so native HTML5 validation never blocks the POST. Cancel simply re-displays
 * the page (FA pattern) — the page's POST handler decides what to do.
 *
 * Buttons use FA's `inputsubmit` class (like the standard Bank Accounts
 * maintenance form) so they submit natively and the page reloads; they must
 * NOT use `ajaxsubmit`, which FA's JsHttpRequest handler intercepts and
 * swallows the controller's redirect. Pass `$useAjax = true` only when the
 * host form is genuinely wired to FA's `$Ajax->activate()` re-query flow.
 *
 * @package Ksfraser\Frontaccounting\HTML
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Ksfraser\Frontaccounting\HTML;

class FormFooter
{
    /** @var string */
    private $submitName;

    /** @var string */
    private $cancelName;

    /** @var string */
    private $submitLabel;

    /** @var string */
    private $cancelLabel;

    /** @var bool Whether the buttons are emitted with FA's `ajaxsubmit` class */
    private $useAjax;

    /**
     * @param string $submitName  Name of the Submit submit-button (gates the save)
     * @param string $cancelName  Name of the Cancel submit-button
     * @param string $submitLabel Displayed label of the Submit button
     * @param string $cancelLabel Displayed label of the Cancel button
     * @param bool   $useAjax     Emit `ajaxsubmit` buttons (default false = native submit)
     *
     * @since 1.0.0
     */
    public function __construct(
        string $submitName = 'submit',
        string $cancelName = 'cancel',
        string $submitLabel = 'Submit',
        string $cancelLabel = 'Cancel',
        bool $useAjax = false
    ) {
        $this->submitName  = $submitName;
        $this->cancelName  = $cancelName;
        $this->submitLabel = $submitLabel;
        $this->cancelLabel = $cancelLabel;
        $this->useAjax     = $useAjax;
    }

    /**
     * @return string Name of the Submit submit-button
     *
     * @since 1.0.0
     */
    public function getSubmitName(): string
    {
        return $this->submitName;
    }

    /**
     * @return string Name of the Cancel submit-button
     *
     * @since 1.0.0
     */
    public function getCancelName(): string
    {
        return $this->cancelName;
    }

    /**
     * Echo the centred Submit + Cancel button row.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function render(): void
    {
        echo '<center>';
        echo $this->submitButton($this->submitName, $this->submitLabel);
        echo '&nbsp;';
        echo $this->submitButton($this->cancelName, $this->cancelLabel);
        echo '</center>';
    }

    /**
     * Render the button row into a string.
     *
     * @return string Rendered HTML
     *
     * @since 1.0.0
     */
    public function toHtml(): string
    {
        ob_start();
        $this->render();
        return (string) ob_get_clean();
    }

    /**
     * Build a single submit button. All dynamic values are escaped.
     *
     * @param string $name  Button name
     * @param string $label Button label
     * @return string Button HTML
     *
     * @since 1.0.0
     */
    private function submitButton(string $name, string $label): string
    {
        $name  = htmlspecialchars($name, ENT_QUOTES);
        $label = htmlspecialchars($this->localise($label), ENT_QUOTES);
        $class = $this->useAjax ? 'ajaxsubmit' : 'inputsubmit';

        return '<button class="' . $class . '" type="submit" formnovalidate name="' . $name
            . '" id="' . $name . '" value="' . $label . '"><span>' . $label . '</span></button>';
    }

    /**
     * Translate a label when FrontAccounting's _() helper is available.
     *
     * @param string $message Untranslated message
     * @return string Translated message (or original outside FA)
     *
     * @since 1.0.0
     */
    private function localise(string $message): string
    {
        return function_exists('_') ? _($message) : $message;
    }
}
