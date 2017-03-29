<?php

namespace Clear01\Widgets\Nette\UI;

use Clear01\Widgets\IWidgetManager;
use Nette\Application\AbortException;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\UI\Control;
use Nette\Application\UI\Multiplier;
use Nette\Diagnostics\Debugger;
use Nette\Templating\ITemplate;

abstract class BaseWidgetControl extends Control
{

	const VIEW_DEFAULT = 'default';
	const VIEW_EDIT = 'edit';

	/** @persistent */
	public $componentView = self::VIEW_DEFAULT;

	/** @var IWidgetManager */
	protected $widgetManager;

	private $insertedWidgetId;

	private $signalReceivingWidgetId;


	/*
	 * Following methods should fill additional variables, redraw snippets and setup template paths.
	 */
	protected abstract function prepareTemplateRenderDashboardMode(ITemplate $template);
	protected abstract function prepareTemplateRenderEditMode(ITemplate $template);
	protected abstract function prepareTemplateUpdateSingleWidget(ITemplate $template, $widgetIdToUpdate, $widgetWasSignalReceiver);


	public function __construct(IWidgetManager $widgetManager)
	{
		parent::__construct();
		$this->widgetManager = $widgetManager;
	}


	public function renderDefault()
	{
		$this->template->userWidgetIds = $this->widgetManager->getUserWidgetIds();
		$this->prepareTemplateRenderDashboardMode($this->template);
		$this->template->render();
	}

	public function renderEdit() {

		$widgetIdToUpdate = null;

		if($this->signalReceivingWidgetId) {
			$widgetIdToUpdate = $this->signalReceivingWidgetId;
		} elseif($this->insertedWidgetId) {
			$widgetIdToUpdate = $this->insertedWidgetId;
		}

		if($widgetIdToUpdate) { // update single widget
			$this->template->userWidgets = [];

			$this->template->updatedWidgetId = $widgetIdToUpdate;

			if($this->signalReceivingWidgetId) {	// only one widget received signal so only and only his configuration was changed
				$this->template->availableWidgets = [];
				$this->widgetManager->saveWidgetState($this->signalReceivingWidgetId, $this->getComponent('widget-' . $this->signalReceivingWidgetId));
			} else {	// signal was received by this component, so internal configuration may be changed
				$availableWidgets = $this->widgetManager->getAvailableWidgets();
				$this->attachComponents($this->prefixArrayKeys($availableWidgets, 'wa'));
				$this->template->availableWidgets = array_keys($availableWidgets);
			}

			$this->template->uniqueId = $this->getUniqueId();

			$this->prepareTemplateUpdateSingleWidget($this->template, $widgetIdToUpdate, (bool)$this->signalReceivingWidgetId);

			$this->template->render();
		} else { // render edit of all widget
			$this->template->userWidgetIds = $this->widgetManager->getUserWidgetIds();

			$availableWidgets = $this->widgetManager->getAvailableWidgets();
			$this->attachComponents($this->prefixArrayKeys($availableWidgets, 'wa'));
			$this->template->availableWidgets = array_keys($availableWidgets);

			$this->template->uniqueId = $this->getUniqueId();

			$this->prepareTemplateRenderEditMode($this->template);

			$this->template->render();
		}
	}

	public function createComponentWidget() {
		/** @var Multiplier $multiplier */
		return $multiplier = new Multiplier(function($widgetId) use (&$multiplier) {
			/** @var Control $widgetInstance */
			$widgetInstance = $this->widgetManager->getSingleWidgetInstance($widgetId);
			$multiplier->addComponent($widgetInstance, $widgetId);
			if($this->getPresenter()->isSignalReceiver($widgetInstance, true)) {
				$this->signalReceivingWidgetId = $widgetId;
			} else {
				$this->signalReceivingWidgetId = null;
			}
			return $widgetInstance;
		});
	}

	function handleDashboard() {
		$this->setView('default');
	}

	function handleEdit()
	{
		$this->setView(self::VIEW_EDIT);
	}

	function handleInsertWidget($widgetTypeId, $beforeWidgetId = null) {
		if($beforeWidgetId == '') {
			$beforeWidgetId = null;
		}
		$widgetId = $this->widgetManager->insertWidget($widgetTypeId, $beforeWidgetId);
		$this->insertedWidgetId = $widgetId;
	}

	function handleRemoveWidget($widgetId) {
		try {
			$this->widgetManager->removeWidget($widgetId);
		} catch(\Exception $e) {
			Debugger::log($e);
			$this->getPresenter()->flashMessage('Widget could not be removed. Please, try to reload current page.', 'alert-danger');
		}
		$this->setView(self::VIEW_EDIT);
	}

	function handleMoveWidgetBefore($widgetId, $relatedWidgetId) {
		if(!$relatedWidgetId) {
			$relatedWidgetId = null;
		}
		try {
			$this->widgetManager->moveWidgetBefore($widgetId, $relatedWidgetId);
			$this->getPresenter()->sendResponse(new JsonResponse([]));
		}
		catch(AbortException $e) { throw $e; }
		catch(\Exception $e) {
			Debugger::log($e);
			$this->getPresenter()->flashMessage('Widget could not be moved. Please, try to reload current page.', 'alert-danger');
		}
	}

	private function prefixArrayKeys($array, $prefix) {
		$out = [];
		foreach($array as $key => $value) {
			$out[$prefix . $key] = $value;
		}
		return $out;
	}

	/**
	 * @param $components Control[]
	 * @return \Nette\Application\UI\Control[]
	 */
	protected function attachComponents($components)
	{
		foreach($components as $componentName => $component) {
			$this->addComponent($component, $componentName);
		}
		return $components;
	}

	public function setView($view) {
		$this->componentView = $view;
	}

	public function render() {
		call_user_func([$this, 'render' . ucfirst($this->componentView)]);
	}

}