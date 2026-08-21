<?php

declare(strict_types=1);

use Gait\FilamentMobile\Introspection\RichContentAdapter;
use Gait\FilamentMobile\Introspection\TagSeparatorAdapter;
use Gait\FilamentMobile\MobileCard;
use Gait\MobileCore\RecordSerializer;
use Illuminate\Database\Eloquent\Model;

/**
 * The write pilot's worst finding, and one no fixture could have shown:
 * every translatable field serialised as null.
 *
 * `data_get($record, 'title.ar')` resolves `title` through the accessor, which
 * hands back the *current locale's string*, and reading `ar` off a string is
 * null. So `GET /{resource}/{id}` answered `{"title": {"ar": null, "en": null}}`
 * for a banner whose row plainly held both — every edit form opened blank over
 * a record that has content, and saving that form wrote the blanks back.
 *
 * Spatie's `HasTranslations` is not a dependency of this package, so the
 * fixture below exposes the same two public methods the fix probes for. That
 * is the actual contract: any model answering `getTranslations()` and
 * `getTranslatableAttributes()` is read through them.
 */
class TranslatableRecord extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    /** @var array<string, array<string, string>> */
    public array $translations = [];

    /** @return list<string> */
    public function getTranslatableAttributes(): array
    {
        return ['title'];
    }

    /** @return array<string, string> */
    public function getTranslations(string $key): array
    {
        return $this->translations[$key] ?? [];
    }

    /** Spatie's accessor behaviour: the current locale's string, not the map. */
    public function getTitleAttribute(): ?string
    {
        return $this->translations['title'][app()->getLocale()] ?? null;
    }
}

function serializeWith(array $fields, Model $record): array
{
    $card = new MobileCard();

    foreach ($fields as $index => $field) {
        $index === 0 ? $card->title($field) : $card->meta($field);
    }

    return (new RecordSerializer($card, 'id', null, new RichContentAdapter(), new TagSeparatorAdapter()))->serialize($record);
}

it('serialises each translation of a dotted translatable path', function () {
    $record = new TranslatableRecord(['id' => 7]);
    $record->translations = ['title' => ['ar' => 'مرحبا', 'en' => 'Hello']];

    expect(serializeWith(['title.ar', 'title.en'], $record))
        ->toBe(['id' => 7, 'title' => ['ar' => 'مرحبا', 'en' => 'Hello']]);
});

it('serialises a locale the record has no translation for as null', function () {
    $record = new TranslatableRecord(['id' => 7]);
    $record->translations = ['title' => ['ar' => 'مرحبا']];

    expect(serializeWith(['title.ar', 'title.fr'], $record)['title'])
        ->toBe(['ar' => 'مرحبا', 'fr' => null]);
});

it('leaves an ordinary dotted path — a relation — alone', function () {
    // The fallback fires only when data_get() came back null AND the head is a
    // declared translatable attribute, so relation paths keep their behaviour.
    $record = new TranslatableRecord(['id' => 7, 'company' => ['name' => 'Acme']]);

    expect(serializeWith(['company.name'], $record)['company']['name'])->toBe('Acme');
});
