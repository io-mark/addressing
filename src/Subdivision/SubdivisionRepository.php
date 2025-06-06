<?php

namespace CommerceGuys\Addressing\Subdivision;

use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepositoryInterface;
use CommerceGuys\Addressing\Locale;

class SubdivisionRepository implements SubdivisionRepositoryInterface
{
    protected AddressFormatRepositoryInterface $addressFormatRepository;

    /**
     * The path where subdivision definitions are stored.
     */
    protected string $definitionPath;

    /**
     * Subdivision definitions.
     */
    protected array $definitions = [];

    /**
     * Parent subdivisions.
     *
     * Used as a cache to speed up instantiating subdivisions with the same
     * parent. Contains only parents instead of all instantiated subdivisions
     * to minimize duplicating the data in $this->definitions, thus reducing
     * memory usage.
     */
    protected array $parents = [];

    /**
     * Creates a SubdivisionRepository instance.
     *
     * @param AddressFormatRepositoryInterface|null $addressFormatRepository The address format repository.
     * @param null $definitionPath Path to the subdivision definitions.
     */
    public function __construct(?AddressFormatRepositoryInterface $addressFormatRepository = null, $definitionPath = null)
    {
        $this->addressFormatRepository = $addressFormatRepository ?: new AddressFormatRepository();
        $this->definitionPath = $definitionPath ?: __DIR__ . '/../../resources/subdivision/';
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id, array $parents): ?Subdivision
    {
        $definitions = $this->loadDefinitions($parents);
        return $this->createSubdivisionFromDefinitions($id, $definitions);
    }

    /**
     * {@inheritdoc}
     */
    public function getAll(array $parents): array
    {
        $definitions = $this->loadDefinitions($parents);
        if (empty($definitions)) {
            return [];
        }

        $subdivisions = [];
        foreach (array_keys($definitions['subdivisions']) as $id) {
            $subdivisions[$id] = $this->createSubdivisionFromDefinitions($id, $definitions);
        }

        return $subdivisions;
    }

    /**
     * {@inheritdoc}
     */
    public function getList(array $parents, ?string $locale = null): array
    {
        $definitions = $this->loadDefinitions($parents);
        if (empty($definitions)) {
            return [];
        }

        $definitionLocale = $definitions['locale'] ?? '';
        $useLocalName = Locale::matchCandidates($locale, $definitionLocale);
        $list = [];
        foreach ($definitions['subdivisions'] as $id => $definition) {
            $list[$id] = $useLocalName ? $definition['local_name'] : $definition['name'];
        }

        return $list;
    }

    /**
     * Checks whether predefined subdivisions exist for the provided parents.
     *
     * @param array $parents The parents (country code, subdivision codes).
     *
     * @return bool TRUE if predefined subdivisions exist for the provided
     *              parents, FALSE otherwise.
     */
    protected function hasData(array $parents): bool
    {
        $countryCode = $parents[0];
        $addressFormat = $this->addressFormatRepository->get($countryCode);
        $depth = $addressFormat->getSubdivisionDepth();
        if ($depth === 0) {
            return false;
        }
        // At least the first level has data.
        $hasData = true;
        if (count($parents) > 1) {
            // After the first level it is possible for predefined subdivisions
            // to exist at a given level, but not for that specific parent.
            // That's why the parent definition has the most precise answer.
            $grandparents = $parents;
            $parentId = array_pop($grandparents);
            $parentGroup = $this->buildGroup($grandparents);
            if (isset($this->definitions[$parentGroup]['subdivisions'][$parentId])) {
                $definition = $this->definitions[$parentGroup]['subdivisions'][$parentId];
                $hasData = !empty($definition['has_children']);
            } else {
                // The parent definition wasn't loaded previously, fallback
                // to guessing based on depth.
                $neededDepth = count($parents);
                $hasData = ($neededDepth <= $depth);
            }
        }
        return $hasData;
    }

    /**
     * Loads the subdivision definitions for the provided parents.
     *
     * @param array $parents The parents (country code, subdivision codes).
     *
     * @return array The subdivision definitions.
     */
    protected function loadDefinitions(array $parents): array
    {
        $group = $this->buildGroup($parents);
        if (isset($this->definitions[$group])) {
            return $this->definitions[$group];
        }

        // If there are predefined subdivisions at this level, try to load them.
        $this->definitions[$group] = [];
        if ($this->hasData($parents)) {
            $filename = $this->definitionPath . $group . '.json';
            if ($rawDefinition = @file_get_contents($filename)) {
                $this->definitions[$group] = json_decode($rawDefinition, true);
                $this->definitions[$group] = $this->processDefinitions($this->definitions[$group]);
            }
        }

        return $this->definitions[$group];
    }

    /**
     * Processes the loaded definitions.
     *
     * Adds keys and values that were removed from the JSON files for brevity.
     *
     * @param array $definitions The definitions.
     *
     * @return array The processed definitions.
     */
    protected function processDefinitions(array $definitions): array
    {
        if (empty($definitions['subdivisions'])) {
            return [];
        }

        foreach ($definitions['subdivisions'] as $id => &$definition) {
            // Add common keys from the root level.
            $definition['country_code'] = $definitions['country_code'];
            $definition['id'] = $id;
            if (isset($definitions['locale'])) {
                $definition['locale'] = $definitions['locale'];
            }
            if (!isset($definition['name'])) {
                $definition['name'] = $id;
            }
            // The code and local_code values are only specified if they
            // don't match the name and local_name ones.
            if (!isset($definition['code']) && isset($definition['name'])) {
                $definition['code'] = $definition['name'];
            }
            if (!isset($definition['local_code']) && isset($definition['local_name'])) {
                $definition['local_code'] = $definition['local_name'];
            }
        }

        return $definitions;
    }

    /**
     * Builds a group from the provided parents.
     *
     * Used for storing a country's subdivisions of a specific level.
     *
     * @param array $parents The parents (country code, subdivision codes).
     *
     * @return string The group.
     */
    protected function buildGroup(array $parents): string
    {
        if (empty($parents)) {
            throw new \InvalidArgumentException('The $parents argument must not be empty.');
        }

        if (count($parents) == 1) {
            $group = $parents[0];
        } elseif (count($parents) == 2 && strlen($parents[1]) <= 3) {
            // The second parent is an ISO code, it can be used as-is.
            $group = implode("-", $parents);
        } else {
            $countryCode = array_shift($parents);
            $group = $countryCode;
            // A dash per key allows the depth to be guessed later.
            $group .= str_repeat('-', count($parents));
            // Hash the remaining keys to ensure that the group is ASCII safe.
            // crc32b is the fastest but has collisions due to its short length.
            // sha1 and md5 are forbidden by many projects and organizations.
            // This is the next fastest option.
            $group .= hash('tiger128,3', implode('-', $parents));
        }

        return $group;
    }

    /**
     * Creates a subdivision object from the provided definitions.
     *
     * @param string $id          The subdivision id.
     * @param array  $definitions The subdivision definitions.
     */
    protected function createSubdivisionFromDefinitions(string $id, array $definitions): ?Subdivision
    {
        if (!isset($definitions['subdivisions'][$id])) {
            // No matching definition found.
            return null;
        }

        $definition = $definitions['subdivisions'][$id];
        // The 'parents' key is omitted when it contains just the country code.
        $definitions += [
            'parents' => [$definitions['country_code']],
        ];
        $parents = $definitions['parents'];
        // Load the parent, if known.
        $definition['parent'] = null;
        if (count($parents) > 1) {
            $grandparents = $parents;
            $parentId = array_pop($grandparents);
            $parentGroup = $this->buildGroup($grandparents);
            if (!isset($this->parents[$parentGroup][$parentId])) {
                $this->parents[$parentGroup][$parentId] = $this->get($parentId, $grandparents);
            }
            $definition['parent'] = $this->parents[$parentGroup][$parentId];
        }
        // Prepare children.
        if (!empty($definition['has_children'])) {
            $childrenParents = array_merge($parents, [$id]);
            $children = new LazySubdivisionCollection($childrenParents);
            $children->setRepository($this);
            $definition['children'] = $children;
        }

        return new Subdivision($definition);
    }
}
