<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Service\Content\ContentCatalogue;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\View\Helper;

/**
 * Puts office-edited text into a public template, always escaped.
 *
 * Every method here returns HTML-safe output, so a template never has to
 * remember to escape and an editor cannot put markup or script on a public
 * page: "<script>" in the editor shows up as the text "<script>".
 *
 *   $this->Content->text('home.hero.heading')   one line, escaped
 *   $this->Content->paragraph('home.hero.lead') escaped, line breaks kept
 *   $this->Content->has('faq.4.question')       false when left empty
 *   $this->Content->phoneLink()                 the phone number as a tel: link
 */
class ContentHelper extends Helper
{
    use LocatorAwareTrait;

    /**
     * The value for a key, unescaped. Only for passing to another helper that
     * escapes, such as a tel: href; templates should use text() or paragraph().
     */
    public function value(string $key): string
    {
        /** @var \App\Model\Table\SiteContentsTable $table */
        $table = $this->fetchTable('SiteContents');

        return $table->current($key);
    }

    /**
     * One line of text, escaped.
     */
    public function text(string $key): string
    {
        return h($this->value($key));
    }

    /**
     * A paragraph, escaped, with the editor's line breaks kept.
     */
    public function paragraph(string $key): string
    {
        return nl2br(h($this->value($key)), false);
    }

    /**
     * Whether a key has anything to show, for optional items such as FAQs.
     */
    public function has(string $key): bool
    {
        return trim($this->value($key)) !== '';
    }

    /**
     * The phone number as a link, e.g. <a href="tel:+61390000000">(03) 9000 0000</a>.
     *
     * @param array<string, mixed> $attributes Extra attributes for the link.
     */
    public function phoneLink(array $attributes = []): string
    {
        $phone = $this->value('contact.phone');
        $href = ContentCatalogue::telHref($phone);
        $extra = '';
        foreach ($attributes as $name => $attribute) {
            $extra .= ' ' . h((string)$name) . '="' . h((string)$attribute) . '"';
        }

        return '<a href="' . h($href) . '"' . $extra . '>' . h($phone) . '</a>';
    }
}
