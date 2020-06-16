<?php

declare(strict_types=1);

namespace Ublaboo\DataGrid\GroupAction;

use Nette;
use Nette\Application\UI\Form;
use Nette\Forms\Container;
use Nette\Forms\Controls\SubmitButton;
use Nette\Forms\Form as NetteForm;
use Ublaboo\DataGrid\DataGrid;
use Ublaboo\DataGrid\Exception\DataGridGroupActionException;
use UnexpectedValueException;

class GroupActionCollection
{

	private const ID_ATTRIBUTE_PREFIX = 'group_action_item_';

	/**
	 * @var array<GroupAction>
	 */
	protected $groupActions = [];

	/**
	 * @var DataGrid
	 */
	protected $datagrid;


	public function __construct(DataGrid $datagrid)
	{
		$this->datagrid = $datagrid;
	}


	public function addToFormContainer(Container $container): void
	{
		/** @var Nette\Application\UI\Form $form */
		$form = $container->lookup('Nette\Application\UI\Form');
		$translator = $form->getTranslator();
		$main_options = [];

		if ($translator === null) {
			throw new UnexpectedValueException();
		}

		/**
		 * First foreach for adding button actions
		 */
		foreach ($this->groupActions as $id => $action) {
			if ($action instanceof GroupButtonAction) {
				$control = $container->addSubmit((string)$id, $action->getTitle());

				/**
				 * User may set a class to the form control
				 */
				$control->setAttribute('class', $action->getClass());

				/**
				 * User may set additional attribtues to the form control
				 */
				foreach ($action->getAttributes() as $name => $value) {
					$control->setAttribute($name, $value);
				}
			}

		}

		/**
		 * Second foreach for filling "main" select
		 */
		foreach ($this->groupActions as $id => $action) {
			if (! $action instanceof GroupButtonAction) {
				$main_options[$id] = $action->getTitle();
			}
		}

		if ($main_options !== []) {
			$groupActionSelect = $container->addSelect('group_action', '', $main_options)
				->setPrompt('ublaboo_datagrid.choose');
		}

		/**
		 * Third for creating select for each "sub"-action
		 */
		foreach ($this->groupActions as $id => $action) {
			$control = null;

			if ($action instanceof GroupSelectAction) {
				if ($action->hasOptions()) {
					if ($action instanceof GroupMultiSelectAction) {
						$control = $container->addMultiSelect((string) $id, '', $action->getOptions());
						$control->setAttribute('data-datagrid-multiselect-id', self::ID_ATTRIBUTE_PREFIX . $id);
						$control->setAttribute('data-style', 'hidden');
						$control->setAttribute('data-selected-icon-check', DataGrid::$iconPrefix . 'check');
					} else {
						$control = $container->addSelect((string) $id, '', $action->getOptions());
					}

					$control->setAttribute('id', self::ID_ATTRIBUTE_PREFIX . $id);
				}
			} elseif ($action instanceof GroupTextAction) {
				$control = $container->addText((string) $id, '');

				$control->setAttribute('id', self::ID_ATTRIBUTE_PREFIX . $id)
					->addConditionOn($groupActionSelect, Form::EQUAL, $id)
					->setRequired($translator->translate('ublaboo_datagrid.choose_input_required'))
					->endCondition();

			} elseif ($action instanceof GroupTextareaAction) {
				$control = $container->addTextArea((string) $id, '');

				$control->setAttribute('id', self::ID_ATTRIBUTE_PREFIX . $id)
					->addConditionOn($groupActionSelect, Form::EQUAL, $id)
					->setRequired($translator->translate('ublaboo_datagrid.choose_input_required'));
			}

			if (isset($control)) {
				/**
				 * User may set a class to the form control
				 */
				$control->setAttribute('class', $action->getClass());

				/**
				 * User may set additional attribtues to the form control
				 */
				foreach ($action->getAttributes() as $name => $value) {
					$control->setAttribute($name, $value);
				}
			}
		}

		if ($main_options !== []) {
			foreach (array_keys($this->groupActions) as $id) {
				$groupActionSelect->addCondition(Form::EQUAL, $id)
					->toggle(self::ID_ATTRIBUTE_PREFIX . $id);
			}

			$groupActionSelect->addCondition(Form::FILLED)
				->toggle(
					strtolower($this->datagrid->getFullName()) . 'group_action_submit'
				);

			$container->addSubmit('submit', 'ublaboo_datagrid.execute')
				->setValidationScope([$container])
				->setAttribute(
					'id',
					strtolower($this->datagrid->getFullName()) . 'group_action_submit'
				);
		}

		$form->onSubmit[] = function (NetteForm $form): void {
			$this->submitted($form);
		};
	}


	/**
	 * Pass "sub"-form submission forward to custom submit function
	 */
	public function submitted(NetteForm $form): void
	{
		$submitter = $this->getFormSubmitter($form);

		if (! $submitter instanceof SubmitButton) {
			return;
		}

		$values = (array) $form->getValues();
		$values = $values['group_action'];

		if (
			($submitter->getName() === 'submit' && $submitter->isSubmittedBy())
			 && ($values->group_action === 0 || $values->group_action === null)) {
			return;
		}

		/**
		 * @todo Define items IDs
		 */
		$http_ids = $form->getHttpData(
			Form::DATA_LINE | Form::DATA_KEYS,
			strtolower($this->datagrid->getFullName()) . '_group_action_item[]'
		);

		$ids = array_keys($http_ids);

		if ($submitter->getName() === 'submit') {
			$id = $values->group_action;
			$this->groupActions[$id]->onSelect($ids, $values[$id] ?? null);
			$form['group_action']['group_action']->setValue(null);
		} else {
			$id = $submitter->getName();
			$this->groupActions[$id]->onClick($ids);
		}
	}


	private function getFormSubmitter(NetteForm $form): ?SubmitButton
	{
		if (
			isset($form['group_action']['submit'])
			&& $form['group_action']['submit']->isSubmittedBy()
		) {
			return $form['group_action']['submit'];
		}

		foreach ($form['group_action']->getComponents() as $component) {
			if (
				$component instanceof SubmitButton
				&& $component->isSubmittedBy()
			) {
				return $component;
			}
		}

		return null;
	}


	/**
	 * Add one group button action to collection of actions
	 */
	public function addGroupButtonAction(string $title, ?string $class = null): GroupAction
	{
		if (count($this->groupActions) > 0) {
			$id = count($this->groupActions) + 1;
		} else {
			$id = 1;
		}

		return $this->groupActions[$id] = new GroupButtonAction($title, $class);
	}


	/**
	 * Add one group action (select box) to collection of actions
	 */
	public function addGroupSelectAction(string $title, array $options): GroupAction
	{
		if (count($this->groupActions) > 0) {
			$id = count($this->groupActions) + 1;
		} else {
			$id = 1;
		}

		return $this->groupActions[$id] = new GroupSelectAction($title, $options);
	}


	/**
	 * Add one group action (multiselect box) to collection of actions
	 */
	public function addGroupMultiSelectAction(string $title, array $options): GroupAction
	{
		if (count($this->groupActions) > 0) {
			$id = count($this->groupActions) + 1;
		} else {
			$id = 1;
		}

		return $this->groupActions[$id] = new GroupMultiSelectAction($title, $options);
	}


	/**
	 * Add one group action (text input) to collection of actions
	 */
	public function addGroupTextAction(string $title): GroupAction
	{
		if (count($this->groupActions) > 0) {
			$id = count($this->groupActions) + 1;
		} else {
			$id = 1;
		}

		return $this->groupActions[$id] = new GroupTextAction($title);
	}


	/**
	 * Add one group action (textarea) to collection of actions
	 */
	public function addGroupTextareaAction(string $title): GroupAction
	{
		if (count($this->groupActions) > 0) {
			$id = count($this->groupActions) + 1;
		} else {
			$id = 1;
		}

		return $this->groupActions[$id] = new GroupTextareaAction($title);
	}


	public function getGroupAction(string $title): GroupAction
	{
		foreach ($this->groupActions as $action) {
			if ($action->getTitle() === $title) {
				return $action;
			}
		}

		throw new DataGridGroupActionException("Group action $title does not exist.");
	}
}
