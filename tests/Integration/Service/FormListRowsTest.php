<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Repository\ListItemRepository;
use App\Service\Form\FormDataNormalizer;
use App\Service\Form\FormDataValidator;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormDefinitionNormalizer;
use App\Service\Form\FormListOptions;
use App\Tests\Integration\RepositoryTestCase;

/**
 * The rows of a fixed table taken from a list of the centre: one per active leaf, only those associated with who delivers the document
 * (with the associations as the rest of the application reads them: inherited from above, replaced by an item's own, through an
 * intermediate subprofile), all of them when that person has none or the table does not ask, and kept as «gone» while a row that no
 * longer belongs holds something.
 */
final class FormListRowsTest extends RepositoryTestCase
{
    private EducationalCentre $centre;
    private SpecificProfile $headOfDepartment;
    /** @var array<string, ListItem> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('IES')->setCity('Linares');
        $this->persist($this->centre);

        // The list of the profile: Departamento › Área científica › (Ciencias, Informática); Departamento › Lengua.
        $departments                  = $this->item('Departamento');
        $area                         = $this->item('Área científica', $departments, 0);
        $this->items['ciencias']      = $this->item('Ciencias', $area, 0);
        $this->items['informatica']   = $this->item('Informática', $area, 1);
        $this->items['lengua']        = $this->item('Lengua', $departments, 1);
        $this->headOfDepartment       = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefatura de departamento')->setListItem($departments);
        $this->persist($this->headOfDepartment);

        // The list the rows come from: Materia › Ciencias naturales › (Física, Química); Matemáticas; Tecnología; Latín (inactive).
        $this->items['materia']    = $this->item('Materia');
        $natural                   = $this->item('Ciencias naturales', $this->items['materia'], 0);
        $this->items['fisica']     = $this->item('Física', $natural, 0);
        $this->items['quimica']    = $this->item('Química', $natural, 1);
        $this->items['matematicas'] = $this->item('Matemáticas', $this->items['materia'], 1);
        $this->items['tecnologia'] = $this->item('Tecnología', $this->items['materia'], 2);
        $this->items['latin']      = $this->item('Latín', $this->items['materia'], 3)->setActive(false);

        // Física and Química inherit the group's (Ciencias); Química has its own, which adds to it (Informática); Matemáticas is
        // Ciencias'; Tecnología is the whole area's (an intermediate subprofile: both departments under it).
        $natural->addProfileAssociation($this->headOfDepartment, $this->items['ciencias']);
        $this->items['quimica']->addProfileAssociation($this->headOfDepartment, $this->items['informatica']);
        $this->items['matematicas']->addProfileAssociation($this->headOfDepartment, $this->items['ciencias']);
        $this->items['tecnologia']->addProfileAssociation($this->headOfDepartment, $area);
        $this->flush();
        $this->em->clear();
        self::getContainer()->get(ListItemRepository::class)->reset();
        $this->centre = $this->em->getRepository(EducationalCentre::class)->find($this->centre->getId()) ?? throw new \LogicException();
    }

    private function item(string $name, ?ListItem $parent = null, int $position = 0): ListItem
    {
        $item = (new ListItem())->setEducationalCentre($this->centre)->setName($name)->setParent($parent)->setPosition($position);
        $this->persist($item);

        return $item;
    }

    /** @return array<string, mixed> a fixed table «Materias» whose rows are the leaves of «Materia» */
    private function definition(bool $own = true): array
    {
        return self::getContainer()->get(FormDefinitionNormalizer::class)->normalize(['sections' => [['title' => 'Materias', 'blocks' => [[
            'key' => FormDefinition::newKey(), 'type' => 'table', 'label' => 'Materias', 'mode' => 'fixed', 'rowHeader' => 'Materia',
            'rowsList' => $this->items['materia']->getId()->toRfc4122(), 'rowsOwn' => $own,
            'columns' => [['label' => 'Aprobados (%)', 'fieldType' => 'number'], ['label' => 'Doble', 'fieldType' => 'calculated', 'formula' => '1']],
        ]]]]]);
    }

    /** @return list<string> the names of the rows the table gets */
    private function rows(array $definition, ?string $department, array $data = []): array
    {
        $slot     = $department === null ? null : ['profile' => $this->headOfDepartment->getId()->toRfc4122(), 'subprofile' => $this->items[$department]->getId()->toRfc4122()];
        $expanded = self::getContainer()->get(FormListOptions::class)->expand($definition, $this->centre, $data, $slot);

        return array_map(static fn (array $r): string => $r['label'] . (($r['gone'] ?? false) ? ' (gone)' : ''), $expanded['sections'][0]['blocks'][0]['rows']);
    }

    public function testTheRowsAreTheItemsOfWhoDeliversItWithTheAssociationsAsTheApplicationReadsThem(): void
    {
        $def = $this->definition();
        self::assertSame([], $def['sections'][0]['blocks'][0]['rows'], 'never stored in the model');

        self::assertSame(['Ciencias naturales › Física', 'Ciencias naturales › Química', 'Matemáticas', 'Tecnología'], $this->rows($def, 'ciencias'), 'inherited, own and through the area; Química too: its own association adds to the inherited one');
        self::assertSame(['Ciencias naturales › Química', 'Tecnología'], $this->rows($def, 'informatica'));
        self::assertSame(['Ciencias naturales › Física', 'Ciencias naturales › Química', 'Matemáticas', 'Tecnología'], $this->rows($def, 'lengua'), 'associated with none: all the active ones');
    }

    public function testWhoDeliversIsReadFromTheKeyOfTheSlot(): void
    {
        $profile = $this->headOfDepartment->getId()->toRfc4122();
        $sub     = $this->items['ciencias']->getId()->toRfc4122();
        self::assertSame(['profile' => $profile, 'subprofile' => $sub], \App\Model\ActivitySubmissionSlot::deliveredBy($profile . ':' . $sub . '::'));
        self::assertSame(['profile' => $profile, 'subprofile' => null], \App\Model\ActivitySubmissionSlot::deliveredBy($profile . ':::'));
        self::assertNull(\App\Model\ActivitySubmissionSlot::deliveredBy('otra:::'));
    }

    public function testWithoutTheCheckboxOrWithoutAnybodyDeliveringItAreAllTheActiveItems(): void
    {
        $all = ['Ciencias naturales › Física', 'Ciencias naturales › Química', 'Matemáticas', 'Tecnología'];
        self::assertSame($all, $this->rows($this->definition(false), 'ciencias'));
        self::assertSame($all, $this->rows($this->definition(), null), 'a blank model, a preview');
    }

    public function testARowThatNoLongerBelongsStaysWhileItHoldsSomethingAndBlocksTheDelivery(): void
    {
        $def    = $this->definition();
        $block  = $def['sections'][0]['blocks'][0];
        [$pct, $calc] = array_column($block['columns'], 'key');
        $latin  = $this->items['latin']->getId()->toRfc4122();
        $fisica = $this->items['fisica']->getId()->toRfc4122();
        // Latín (now inactive) has a value; Física (not of Informática) only has what a calculated column holds: that is not typed.
        $raw = ['tables' => [$block['key'] => [['_key' => $latin, $pct => 80], ['_key' => $fisica, $calc => 2]]]];

        self::assertSame(['Ciencias naturales › Química', 'Tecnología', 'Latín (ya no disponible) (gone)'], $this->rows($def, 'informatica', $raw));

        $slot     = ['profile' => $this->headOfDepartment->getId()->toRfc4122(), 'subprofile' => $this->items['informatica']->getId()->toRfc4122()];
        $expanded = self::getContainer()->get(FormListOptions::class)->expand($def, $this->centre, $raw, $slot);
        $data     = self::getContainer()->get(FormDataNormalizer::class)->normalize($expanded, $raw);
        self::assertCount(3, $data['tables'][$block['key']], 'the two of Informática and the one gone; Física is dropped');
        $codes = array_column(self::getContainer()->get(FormDataValidator::class)->problems($expanded, $data), 'code');
        self::assertContains('row_gone', $codes);

        // Emptied, it is gone for good and nothing blocks.
        unset($raw['tables'][$block['key']][0][$pct]);
        $expanded = self::getContainer()->get(FormListOptions::class)->expand($def, $this->centre, $raw, $slot);
        $data     = self::getContainer()->get(FormDataNormalizer::class)->normalize($expanded, $raw);
        self::assertNotContains('row_gone', array_column(self::getContainer()->get(FormDataValidator::class)->problems($expanded, $data), 'code'));
        self::assertCount(2, $data['tables'][$block['key']]);
    }

    /** @return array<string, mixed> a section «Materia» repeated by the leaves of «Materia», its title naming each, with one field */
    private function repeated(bool $own = true): array
    {
        return self::getContainer()->get(FormDefinitionNormalizer::class)->normalize(['sections' => [[
            'key' => FormDefinition::newKey(), 'title' => 'Materia: {{Elemento de la lista|copy.label}}',
            'repeat' => ['mode' => 'list', 'list' => $this->items['materia']->getId()->toRfc4122(), 'own' => $own, 'items' => [['key' => 'x', 'label' => 'never stored']]],
            'blocks' => [['key' => FormDefinition::newKey(), 'type' => 'field', 'fieldType' => 'short', 'label' => 'Valoración']],
        ]]]);
    }

    public function testASectionRepeatedByAListHasACopyPerItemOfWhoDeliversItNamedAfterIt(): void
    {
        $def = $this->repeated();
        self::assertSame(['mode' => 'list', 'list' => $this->items['materia']->getId()->toRfc4122(), 'own' => true], $def['sections'][0]['repeat'], 'the items are never stored');

        $slot     = ['profile' => $this->headOfDepartment->getId()->toRfc4122(), 'subprofile' => $this->items['informatica']->getId()->toRfc4122()];
        $expanded = self::getContainer()->get(FormListOptions::class)->expand($def, $this->centre, [], $slot);
        $data     = self::getContainer()->get(FormDataNormalizer::class)->initial($expanded);
        $copies   = $data['copies'][$def['sections'][0]['key']];
        self::assertSame([$this->items['quimica']->getId()->toRfc4122(), $this->items['tecnologia']->getId()->toRfc4122()], array_column($copies, '_key'), 'one copy per item, keyed by it');

        $view = self::getContainer()->get(\App\Service\Form\FormResolver::class)->resolve($expanded, $data);
        self::assertSame(['Materia: Ciencias naturales › Química', 'Materia: Tecnología'], array_column($view, 'title'));

        // A blank model: every item.
        $blank = self::getContainer()->get(FormListOptions::class)->expand($def, $this->centre);
        self::assertCount(4, self::getContainer()->get(FormDataNormalizer::class)->sample($blank)['copies'][$def['sections'][0]['key']]);
    }

    public function testACopyThatNoLongerBelongsStaysWhileItHoldsSomethingAndBlocksTheDelivery(): void
    {
        $def     = $this->repeated();
        $section = $def['sections'][0]['key'];
        $field   = $def['sections'][0]['blocks'][0]['key'];
        $slot    = ['profile' => $this->headOfDepartment->getId()->toRfc4122(), 'subprofile' => $this->items['informatica']->getId()->toRfc4122()];
        // Matemáticas is not of Informática: with something typed it stays, marked; Física, with nothing, goes.
        $raw = ['copies' => [$section => [
            ['_key' => $this->items['matematicas']->getId()->toRfc4122(), 'fields' => [$field => 'Bien']],
            ['_key' => $this->items['fisica']->getId()->toRfc4122(), 'fields' => []],
        ]]];
        $lists    = self::getContainer()->get(FormListOptions::class);
        $expanded = $lists->expand($def, $this->centre, $raw, $slot);
        self::assertSame(['Ciencias naturales › Química', 'Tecnología', 'Matemáticas (ya no corresponde)'], array_column($expanded['sections'][0]['repeat']['items'], 'label'), 'still active, but not of who delivers it');
        self::assertTrue($expanded['sections'][0]['repeat']['items'][2]['gone']);

        $data = self::getContainer()->get(FormDataNormalizer::class)->normalize($expanded, $raw);
        self::assertCount(3, $data['copies'][$section]);
        self::assertContains('copy_gone', array_column(self::getContainer()->get(FormDataValidator::class)->problems($expanded, $data), 'code'));

        // Frozen at delivery: the items it had (never a gone one), and back from the snapshot; by path in the JSON library.
        $snapshot = $lists->snapshot($lists->expand($def, $this->centre, [], $slot), []);
        self::assertSame(['Ciencias naturales › Química', 'Tecnología'], array_column($snapshot[$section], 'label'));
        $paths = $lists->toPaths($def, $this->centre);
        self::assertSame(['Materia'], $paths['sections'][0]['repeat']['listPath']);
        [$back] = $lists->fromPaths($paths, $this->centre);
        self::assertSame($this->items['materia']->getId()->toRfc4122(), $back['sections'][0]['repeat']['list']);
    }

    public function testADeliveryFreezesAllTheRowsWithTheirNamesAndTheModelTravelsByPath(): void
    {
        $lists    = self::getContainer()->get(FormListOptions::class);
        $def      = $this->definition();
        $key      = $def['sections'][0]['blocks'][0]['key'];
        $slot     = ['profile' => $this->headOfDepartment->getId()->toRfc4122(), 'subprofile' => $this->items['informatica']->getId()->toRfc4122()];
        $snapshot = $lists->snapshot($lists->expand($def, $this->centre, [], $slot), []);
        self::assertSame(['Ciencias naturales › Química', 'Tecnología'], array_column($snapshot[$key], 'label'), 'every row, even the empty ones');

        // The JSON library: the list goes by its names, and comes back as this centre's item.
        $paths = $lists->toPaths($def, $this->centre);
        self::assertSame(['Materia'], $paths['sections'][0]['blocks'][0]['rowsListPath']);
        self::assertArrayNotHasKey('rowsList', $paths['sections'][0]['blocks'][0]);
        [$back, $unlinked] = $lists->fromPaths($paths, $this->centre);
        self::assertSame([[], $this->items['materia']->getId()->toRfc4122()], [$unlinked, $back['sections'][0]['blocks'][0]['rowsList']]);

        // A model whose list offers nothing cannot be sent to review.
        $broken = $this->definition();
        $broken['sections'][0]['blocks'][0]['rowsList'] = FormDefinition::newKey();
        self::assertSame(['Materias'], $lists->emptySources($broken, $this->centre));
    }
}
