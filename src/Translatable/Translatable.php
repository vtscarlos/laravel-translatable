<?php namespace Dimsav\Translatable;

use App;
use Dimsav\Translatable\Exception\LocalesNotDefinedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Model;

trait Translatable
{
    /**
     * Alias for getTranslation()
     *
     * @param string $locale
     * @param bool $withFallback
     *
     * @return Model|null
     */
    public function translate($locale = null, $withFallback = false)
    {
        return $this->getTranslation($locale, $withFallback);
    }

    /**
     * Alias for getTranslation()
     *
     * @param string $locale
     *
     * @return Model|null
     */
    public function translateOrDefault($locale)
    {
        return $this->getTranslation($locale, true);
    }

    /**
     * Alias for getTranslationOrNew()
     *
     * @param string $locale
     *
     * @return Model|null
     */
    public function translateOrNew($locale)
    {
        return $this->getTranslationOrNew($locale);
    }

    /**
     * @param null      $locale
     * @param bool|null $withFallback
     *
     * @return Model|null
     */
    public function getTranslation($locale = null, $withFallback = null)
    {
        $locale = $locale ?: App::getLocale();

        $withFallback = $withFallback === null ? $this->useFallback() : $withFallback;

        if ($this->getTranslationByLocaleKey($locale)) {
            $translation = $this->getTranslationByLocaleKey($locale);
        } elseif ($withFallback
            && $this->getFallbackLocale()
            && $this->getTranslationByLocaleKey($this->getFallbackLocale())
        ) {
            $translation = $this->getTranslationByLocaleKey($this->getFallbackLocale());
        } else {
            $translation = null;
        }

        return $translation;
    }

    /**
     * @param string $locale
     *
     * @return bool
     */
    public function hasTranslation($locale = null)
    {
        $locale = $locale ?: App::getLocale();

        foreach ($this->translations as $translation) {
            if ($translation->getAttribute($this->getLocaleKey()) == $locale) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public function getTranslationModelName()
    {
        return $this->translationModel ?: $this->getTranslationModelNameDefault();
    }

    /**
     * @return string
     */
    public function getTranslationModelNameDefault()
    {
        $config = App::make('config');

        return get_class($this).$config->get('translatable.translation_suffix', 'Translation');
    }

    /**
     * @return string
     */
    public function getRelationKey()
    {
        return $this->translationForeignKey ?: $this->getForeignKey();
    }

    /**
     * @return string
     */
    public function getLocaleKey()
    {
        $config = App::make('config');

        return $this->localeKey ?: $config->get('translatable.locale_key', 'locale');
    }

    /**
     * @return mixed
     */
    public function translations()
    {
        return $this->hasMany($this->getTranslationModelName(), $this->getRelationKey());
    }

    /**
     * @param string $key
     *
     * @return mixed|null
     */
    public function getAttribute($key)
    {
        if ($this->isTranslationAttribute($key)) {
            if ($this->getTranslation() === null) {
                return;
            }

            return $this->getTranslation()->$key;
        }

        return parent::getAttribute($key);
    }

    /**
     * @param string $key
     * @param $value
     */
    public function setAttribute($key, $value)
    {
        if ($this->isTranslationAttribute($key)) {
            $this->getTranslationOrNew(App::getLocale())->$key = $value;
        } else {
            parent::setAttribute($key, $value);
        }
    }

    /**
     * @param array $options
     *
     * @return bool
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            if (count($this->getDirty()) > 0) {
                // If $this->exists and dirty, parent::save() has to return true. If not,
                // an error has occurred. Therefore we shouldn't save the translations.
                if (parent::save($options)) {
                    return $this->saveTranslations();
                }

                return false;
            } else {
                // If $this->exists and not dirty, parent::save() skips saving and returns
                // false. So we have to save the translations
                if ($saved = $this->saveTranslations()) {
                    $this->fireModelEvent('saved', false);
                }

                return $saved;
            }
        } elseif (parent::save($options)) {
            // We save the translations only if the instance is saved in the database.
            return $this->saveTranslations();
        }

        return false;
    }

    /**
     * @param string $locale
     *
     * @return Model|null
     */
    protected function getTranslationOrNew($locale)
    {
        if (($translation = $this->getTranslation($locale, false)) === null) {
            $translation = $this->getNewTranslation($locale);
        }

        return $translation;
    }

    /**
     * @param array $attributes
     *
     * @return mixed
     */
    public function fill(array $attributes)
    {
        foreach ($attributes as $key => $values) {
            if ($this->isKeyALocale($key)) {
                $this->getTranslationOrNew($key)->fill($values);
                unset($attributes[$key]);
            }
            elseif ($this->isTranslationAttribute($key)) {
                $this->getTranslationOrNew(App::getLocale())->fill([$key => $values]);
                unset($attributes[$key]);
            }
        }

        return parent::fill($attributes);
    }

    /**
     * @param $key
     *
     * @return mixed|null
     */
    private function getTranslationByLocaleKey($key)
    {
        foreach ($this->translations as $translation) {
            if ($translation->getAttribute($this->getLocaleKey()) == $key) {
                return $translation;
            }
        }

        return;
    }

    /**
     * @return string|null
     */
    private function getFallbackLocale()
    {
        return App::make('config')->get('translatable.fallback_locale');
    }

    /**
     * @return bool|null
     */
    private function useFallback()
    {
        if (isset($this->useTranslationFallback) && $this->useTranslationFallback !== null) {
            return $this->useTranslationFallback;
        }

        return App::make('config')->get('translatable.use_fallback');
    }

    /**
     * @param $key
     *
     * @return bool
     */
    protected function isTranslationAttribute($key)
    {
        return in_array($key, $this->translatedAttributes);
    }

    /**
     * @param $key
     *
     * @return bool
     * @throws LocalesNotDefinedException
     */
    protected function isKeyALocale($key)
    {
        $locales = $this->getLocales();

        return in_array($key, $locales);
    }

    /**
     * @return array
     * @throws LocalesNotDefinedException
     */
    protected function getLocales()
    {
        $config = App::make('config');
        $locales = (array) $config->get('translatable.locales', []);

        if (empty($locales)) {
            throw new LocalesNotDefinedException('Please make sure you have run "php artisan config:publish dimsav/laravel-translatable" '.
            ' and that the locales configuration is defined.');
        }

        return $locales;
    }

    /**
     * @return bool
     */
    protected function saveTranslations()
    {
        $saved = true;
        foreach ($this->translations as $translation) {
            if ($saved && $this->isTranslationDirty($translation)) {
                $translation->setAttribute($this->getRelationKey(), $this->getKey());
                $saved = $translation->save();
            }
        }

        return $saved;
    }

    /**
     * @param Model $translation
     *
     * @return bool
     */
    protected function isTranslationDirty(Model $translation)
    {
        $dirtyAttributes = $translation->getDirty();
        unset($dirtyAttributes[$this->getLocaleKey()]);

        return count($dirtyAttributes) > 0;
    }

    /**
     * @param $locale
     *
     * @return mixed
     */
    public function getNewTranslation($locale)
    {
        $modelName = $this->getTranslationModelName();
        $translation = new $modelName();
        $translation->setAttribute($this->getLocaleKey(), $locale);
        $this->translations->add($translation);

        return $translation;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function __isset($key)
    {
        return ($this->isTranslationAttribute($key) || parent::__isset($key));
    }

    /**
     * @param Builder $query
     * @param string  $locale
     *
     * @return Builder
     */
    public function scopeTranslatedIn(Builder $query, $locale)
    {
        return $query->whereHas('translations', function (Builder $q) use ($locale) {
            $q->where($this->getLocaleKey(), '=', $locale);
        });
    }

    /**
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeTranslated(Builder $query)
    {
        return $query->has('translations');
    }

    /**
     * Adds scope to get a list of translated attributes, using the current locale.
     *
     * Example usage: Country::scopeListsTranslations('name')->get()->toArray()
     * Will return an array with items:
     *  [
     *      'id' => '1',                // The id of country
     *      'name' => 'Griechenland'    // The translated name
     *  ]
     *
     * @param Builder $query
     * @param string  $translationField
     */
    public function scopeListsTranslations(Builder $query, $translationField)
    {
        $withFallback = $this->useFallback();

        $query
            ->select($this->getTable().'.'.$this->getKeyName(), $this->getTranslationsTable().'.'.$translationField)
            ->leftJoin($this->getTranslationsTable(), $this->getTranslationsTable().'.'.$this->getRelationKey(), '=', $this->getTable().'.'.$this->getKeyName())
            ->where($this->getTranslationsTable().'.'.$this->getLocaleKey(), App::getLocale())
        ;
        if ($withFallback) {
            $query->orWhere(function (Builder $q) {
                $q->where($this->getTranslationsTable().'.'.$this->getLocaleKey(), $this->getFallbackLocale())
                    ->whereNotIn($this->getTranslationsTable().'.'.$this->getRelationKey(), function (QueryBuilder $q) {
                        $q->select($this->getTranslationsTable().'.'.$this->getRelationKey())
                            ->from($this->getTranslationsTable())
                            ->where($this->getTranslationsTable().'.'.$this->getLocaleKey(), App::getLocale());
                    });
            });
        }
    }

    /**
     * @return mixed
     */
    public function toArray()
    {
        $attributes = parent::toArray();

        foreach ($this->translatedAttributes as $field) {
            if ($translations = $this->getTranslation()) {
                $attributes[$field] = $translations->$field;
            }
        }

        return $attributes;
    }

    /**
     * @return string
     */
    private function getTranslationsTable()
    {
        return App::make($this->getTranslationModelName())->getTable();
    }
}
