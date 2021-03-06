<?php
declare(strict_types=1);

namespace RobThree\UrlGenerator;

class UrlGenerator
{
    /**
     * Key for lookup array to store a lookup result (index). Can, technically, be anything except any of the (lowercase) chars allowed in the urls.
     */
    private const INDEXPLACEHOLDER = 0;

    /**
     * Holds an array with category => string-array data with the words to use in the url
     */
    private array $data;
    /**
     * Used to keep track of the categories contained in the data
     */
    private ?array $categories;
    /**
     * Will hold (reversed)string lookup to word index data
     */
    private array $lookup;
    /**
     * Separator to use, if any
     */
    private ?string $separator;
    /**
     * Format to use (one of 'ucfirst', 'lcfirst', 'upper', 'lower' or null for no formatting
     */
    private ?string $format;

    /**
     * UrlGenerator constructor.
     *
     * @throws UrlGeneratorException
     */
    public function __construct(array $words, ?array $categories = null, ?string $separator = '-', ?string $format = null)
    {
        if (count($words) === 0) {
            throw new UrlGeneratorException('No words specified');
        }
        $this->data = $words;

        // No categories specified? "Autodetect" categories to use
        if ($categories === null) {
            $categories = array_keys($this->data);
        }

        // Ensure we have categories
        if (count($categories) === 0) {
            throw new UrlGeneratorException('Categories must be an array with size > 0');
        }
        $this->categories = $categories;
        $this->lookup = [];

        // Check categories and build lookup table
        foreach (array_unique($this->categories) as $k) {
            if (!is_string($k) || strlen($k) === 0) {
                throw new UrlGeneratorException(sprintf('Category "%s" is invalid', $k));
            }
            if (!array_key_exists($k, $this->data) || !is_array($this->data[$k]) || count($this->data[$k]) === 0) {
                throw new UrlGeneratorException(sprintf('Category "%s" not found in datafile, category is not an array or category is an empty array', $k));
            }

            // Ensure unique, normalized, values (make sure we use array_values, because array_unique will preserve the 'key' which is our index, causing missig indices on duplicate values)
            $this->data[$k] = array_values(array_unique(array_map(function ($s) {
                return strtolower(trim($s));
            }, $this->data[$k])));

            // Initialize lookup structure; add all words to our lookup
            $this->lookup[$k] = [];
            foreach (array_flip($this->data[$k]) as $w => $i) // The array_flip gives us indices (word=>index) AND deduplicates items in a single go
            {
                $this->addLookup($k, $w, $i);
            }
        }

        // Set other properties
        $this->separator = $separator;

        $format = strtolower(trim($format ?? ''));
        switch ($format) {
            case '':
            case 'ucfirst':
            case 'lcfirst':
            case 'upper':
            case 'lower':
                $this->format = $format;
                break;
            default:
                throw new UrlGeneratorException(sprintf('Unsupported format "%s"', $format));
        }
    }

    /**
     * Converts an id into a URL value
     * @throws UrlGeneratorException
     */
    public function toURL(int $id): string
    {
        if ($id < 0) {
            throw new UrlGeneratorException('ID must be a postive integer');
        }

        $value = $id;                               // Initialize value to id value
        $catIndex = count($this->categories) - 1;   // Start at last category
        $result = [];                               // Array of words we calculated
        $radix = count($this->data[$this->categories[$catIndex]]);                 // Get radix

        // Below is basically a decimal to base-N conversion where each N may differ on the number of words in that category
        do {
            $result[] = $this->formatWord($this->getWord($catIndex, $value % $radix));  // Determine word for this category
            $value = (int)($value / $radix);                                            // Calculate new value
            $catIndex = max(--$catIndex, 0);                                            // Next category (going from highest down to 0, repeating 0 if required)
            $radix = count($this->data[$this->categories[$catIndex]]);                  // Get radix
        } while ($value > 0);

        // Return string, glued with optional separator, in correct order
        return implode($this->separator, array_reverse($result));
    }

    /**
     * Parses a URL value and returns the integer equivalent
     * @throws UrlGeneratorException
     */
    public function parseUrl(string $text): int
    {
        $value = strtolower(trim($text));               // Normalize value
        if (strlen($value) === 0)                       // Ensure we have something to parse
        {
            throw new UrlGeneratorException('No text specified');
        }

        $step = 1;                                      // Initialize step
        $result = 0;                                    // Initialize result, where the calculated ID will be stored
        $catIndex = count($this->categories) - 1;       // Start at last category
        try {
            // Below is basically a base-N to decimal conversion where each N may differ on the number of words in that category
            while ($value) {
                $ix = $this->lookupWordIndex($this->categories[$catIndex], $value); // Find the index of the word
                $result += ($ix * $step);                                           // Add the index * step to the calculated result
                $step *= count($this->data[$this->categories[$catIndex]]);          // Increase step size
                $value = substr($value, 0, -(strlen($this->getWord($catIndex, $ix)) + strlen($this->separator)));  // Strip found word from text
                $catIndex = max(--$catIndex, 0);                                    // Next category (going from highest down to 0, repeating 0 if required)
            }
        } catch (\Exception $ex) {
            throw new UrlGeneratorException(sprintf('Failed to lookup "%s"', $text));
        }
        // Return calculated ID
        return $result;
    }

    /**
     * Return the word for the given category at the specified index
     */
    private function getWord(int $catIndex, int $index): string
    {
        return $this->data[$this->categories[$catIndex]][$index];
    }

    /**
     * Formats a word depending on the format
     */
    private function formatWord($word): string
    {
        switch ($this->format) {
            case 'ucfirst':
                return ucfirst($word);
            case 'lcfirst':
                return lcfirst($word);
            case 'upper':
                return strtoupper($word);
            case 'lower':
                return strtolower($word);
            default:
                return $word;
        }
    }

    /**
     * Returns the index of a word in the given category
     * @throws UrlGeneratorException
     */
    private function lookupWordIndex(string $category, string $word): int
    {
        $p = &$this->lookup[$category];
        $lastIx = null;
        for ($i = strlen($word) - 1; $i >= 0; $i--) {
            $c = $word[$i];
            if (!array_key_exists($c, $p)) {
                break;
            }

            $p = &$p[$c];
            if (array_key_exists(self::INDEXPLACEHOLDER, $p)) {
                $lastIx = $p[self::INDEXPLACEHOLDER];
            }
        }
        if ($lastIx !== null) {
            /** @noinspection PhpStrictTypeCheckingInspection */
            return $lastIx;
        }

        throw new UrlGeneratorException(sprintf('Failed to lookup "%s"', $word));
    }

    /**
     * Adds a word to the lookup table
     */
    private function addLookup(string $category, string $word, int $index): void
    {
        $p = &$this->lookup[$category];
        for ($i = strlen($word) - 1; $i >= 0; $i--) {
            $c = $word[$i];
            if (!array_key_exists($c, $p)) {
                $p[$c] = [];
            }
            $p = &$p[$c];
        }

        $p[self::INDEXPLACEHOLDER] = $index;
    }
}