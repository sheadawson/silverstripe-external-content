<?php

define('EXTERNALCONTENT', 'external-content');


/**
 * Backend administration pages for the external content module
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD License http://silverstripe.org/bsd-license
 */
class ExternalContentAdmin extends LeftAndMain implements CurrentPageIdentifier, PermissionProvider{
	/**
	 * The URL format to get directly to this controller
	 * @var unknown_type
	 */
	const URL_STUB = 'extadmin';

	/**
	 * URL segment used by the backend 
	 * 
	 * @var string
	 */
	static $url_segment = EXTERNALCONTENT;
	static $url_rule = '$Action//$ID';
	static $menu_title = 'External Content';
	public static $tree_class = 'ExternalContentSource';
	static $allowed_actions = array(
		'addprovider',
		'deleteprovider',
		'deletemarked',
		'CreateProviderForm',
		'DeleteItemsForm',
		'getsubtree',
		'save',
		'migrate',
		'download',
		'view',
		'treeview'
	);


	public function init(){
		parent::init();

		Requirements::css(CMS_DIR . '/css/screen.css');

		Requirements::css(EXTERNALCONTENT . '/css/external-content-admin.css');
		Requirements::javascript(EXTERNALCONTENT . '/javascript/external-content-admin.js');
		
		// Requirements::combine_files(
		// 	'cmsmain.js',
		// 	array_merge(
		// 		array(
		// 			CMS_DIR . '/javascript/CMSMain.js',
		// 			CMS_DIR . '/javascript/CMSMain.EditForm.js',
		// 			CMS_DIR . '/javascript/CMSMain.AddForm.js',
		// 			CMS_DIR . '/javascript/CMSPageHistoryController.js',
		// 			CMS_DIR . '/javascript/CMSMain.Tree.js',
		// 			CMS_DIR . '/javascript/SilverStripeNavigator.js',
		// 			CMS_DIR . '/javascript/SiteTreeURLSegmentField.js'
		// 		),
		// 		Requirements::add_i18n_javascript(CMS_DIR . '/javascript/lang', true, true)
		// 	)
		// );
	}


	/**
	 * Overridden to properly output a value and end, instead of
	 * letting further headers (X-Javascript-Include) be output
	 */
	public function pageStatus() {
		// If no ID is set, we're merely keeping the session alive
		if (!isset($_REQUEST['ID'])) {
			echo '{}';
			return;
		}

		parent::pageStatus();
	}

	/**
	 * Return fake-ID "root" if no ID is found (needed for creating providers... ?)
	 * 
	 * Copied from AssetAdmin, not sure exactly what this is needed for
	 */
	// public function currentPageID() {
	// 	if (isset($_REQUEST['ID']) && preg_match(ExternalContent::ID_FORMAT, $_REQUEST['ID'])) {
	// 		return $_REQUEST['ID'];
	// 	} elseif (preg_match(ExternalContent::ID_FORMAT, $this->urlParams['ID'])) {
	// 		return $this->urlParams['ID'];
	// 	} elseif (strlen(Session::get("{$this->class}.currentPage"))) {
	// 		return Session::get("{$this->class}.currentPage");
	// 	} else {
	// 		return "root";
	// 	}
	// }

	/**
	 * Custom currentPage() method to handle opening the 'root' folder
	 */
	public function currentPage() {
		$id = $this->currentPageID();
		if (preg_match(ExternalContent::ID_FORMAT, $id)) {

			return ExternalContent::getDataObjectFor($id);
		} else if ($id == 'root') {
			return singleton($this->stat('tree_class'));
		}
	}


	public function LinkTreeView() {
		return $this->Link('treeview');
		//return $this->LinkWithSearch(singleton('CMSMain')->Link('treeview'));
	}


	/**
	 * Is the passed in ID a valid
	 * format? 
	 * 
	 * @return boolean
	 */
	public static function isValidId($id) {
		return preg_match(ExternalContent::ID_FORMAT, $id);
	}

	/**
	 * Action to migrate a selected object through to SS
	 * 
	 * @param array $request
	 */
	public function migrate($request) {
		$migrationTarget = isset($request['MigrationTarget']) ? $request['MigrationTarget'] : '';
		$fileMigrationTarget = isset($request['FileMigrationTarget']) ? $request['FileMigrationTarget'] : '';
		$includeSelected = isset($request['IncludeSelected']) ? $request['IncludeSelected'] : 0;
		$includeChildren = isset($request['IncludeChildren']) ? $request['IncludeChildren'] : 0;

		$duplicates = isset($request['DuplicateMethod']) ? $request['DuplicateMethod'] : ExternalContentTransformer::DS_OVERWRITE;

		$selected = isset($request['ID']) ? $request['ID'] : 0;

		$result = array(
			'message' => "Invalid request",
			'status' => false
		);

		if ($selected && ($migrationTarget || $fileMigrationTarget)) {
			// get objects and start stuff
			$target = null;
			$targetType = 'SiteTree';
			if ($migrationTarget) {
				$target = DataObject::get_by_id('SiteTree', $migrationTarget);
			} else {
				$targetType = 'File';
				$target = DataObject::get_by_id('File', $fileMigrationTarget);
			}

			$from = ExternalContent::getDataObjectFor($selected);
			if ($from instanceof ExternalContentSource) {
				$selected = false;
			}

			if (isset($request['Repeat']) && $request['Repeat'] > 0) {
				$job = new ScheduledExternalImportJob($request['Repeat'], $from, $target, $includeSelected, $includeChildren, $targetType, $duplicates, $request);
				singleton('QueuedJobService')->queueJob($job);
			} else {
				$importer = null;
				$importer = $from->getContentImporter($targetType);

				if ($importer) {
					$importer->import($from, $target, $includeSelected, $includeChildren, $duplicates, $request);
				}
			}
			
			
			$result['message'] = "Starting import to " . $target->Title;
			$result['status'] = true;
		}

		echo Convert::raw2json($result);
	}


		/**
	 * Return the edit form
	 * @see cms/code/LeftAndMain#EditForm()
	 */
	public function EditForm($request = null) {
		HtmlEditorField::include_js();

		$cur = $this->currentPageID();
		if ($cur) {
			$record = $this->currentPage();
			if (!$record)
				return false;
			if ($record && !$record->canView())
				return Security::permissionFailure($this);
		}

		if ($this->hasMethod('getEditForm')) {
			return $this->getEditForm($this->currentPageID());
		}

		return false;
	}


	/**
	 * Return the form for editing
	 */
	function getEditForm($id = null, $fields = null) {
		$record = null;

		if(!$id){
			$id = $this->currentPageID();
		}
		
		if ($id && $id != "root") {
			$record = ExternalContent::getDataObjectFor($id);
		}

		if ($record) {
			$fields = $record->getCMSFields();

			// If we're editing an external source or item, and it can be imported
			// then add the "Import" tab.
			$isSource = $record instanceof ExternalContentSource;
			$isItem = $record instanceof ExternalContentItem;

			if (($isSource || $isItem) && $record->canImport()) {
				$allowedTypes = $record->allowedImportTargets();
				if (isset($allowedTypes['sitetree'])) {
					$fields->addFieldToTab('Root.Import', new TreeDropdownField("MigrationTarget", _t('ExternalContent.MIGRATE_TARGET', 'Page to import into'), 'SiteTree'));
				}

				if (isset($allowedTypes['file'])) {
					$fields->addFieldToTab('Root.Import', new TreeDropdownField("FileMigrationTarget", _t('ExternalContent.FILE_MIGRATE_TARGET', 'Folder to import into'), 'Folder'));
				}

				$fields->addFieldToTab('Root.Import', new CheckboxField("IncludeSelected", _t('ExternalContent.INCLUDE_SELECTED', 'Include Selected Item in Import')));
				$fields->addFieldToTab('Root.Import', new CheckboxField("IncludeChildren", _t('ExternalContent.INCLUDE_CHILDREN', 'Include Child Items in Import'), true));

				$duplicateOptions = array(
					ExternalContentTransformer::DS_OVERWRITE => ExternalContentTransformer::DS_OVERWRITE,
					ExternalContentTransformer::DS_DUPLICATE => ExternalContentTransformer::DS_DUPLICATE,
					ExternalContentTransformer::DS_SKIP => ExternalContentTransformer::DS_SKIP,
				);

				$fields->addFieldToTab('Root.Import', new OptionsetField("DuplicateMethod", _t('ExternalContent.DUPLICATES', 'Select how duplicate items should be handled'), $duplicateOptions));
				
				if (class_exists('QueuedJobDescriptor')) {
					$repeats = array(
						0		=> 'None',
						300		=> '5 minutes',
						900		=> '15 minutes',
						1800	=> '30 minutes',
						3600	=> '1 hour',
						33200	=> '12 hours',
						86400	=> '1 day',
						604800	=> '1 week',
					);
					$fields->addFieldToTab('Root.Import', new DropdownField('Repeat', 'Repeat import each ', $repeats));
				}

				$migrateButton = '<p><input type="submit" id="Form_EditForm_Migrate" name="action_migrate" value="' . _t('ExternalContent.IMPORT', 'Start Importing') . '" /></p>';
				$fields->addFieldToTab('Root.Import', new LiteralField('migrate', $migrateButton));
			}

			$fields->push($hf = new HiddenField("ID"));
			$hf->setValue($id);

			$fields->push($hf = new HiddenField("Version"));
			$hf->setValue(1);

			$actions = new FieldList();
			// Only show save button if not 'assets' folder
			if ($record->canEdit()) {
				$actions->push(
					FormAction::create('save',_t('ExternalContent.SAVE','Save'))
						->addExtraClass('ss-ui-action-constructive')->setAttribute('data-icon', 'accept')
				);
			}

			

			$form = new Form($this, "EditForm", $fields, $actions);
			if ($record->ID) {
				$form->loadDataFrom($record);
			} else {
				$form->loadDataFrom(array(
					"ID" => "root",
					"URL" => Director::absoluteBaseURL() . self::$url_segment,
				));
			}

			if (!$record->canEdit()) {
				$form->makeReadonly();
			}

		} else {
			// Create a dummy form
			$fields = new FieldList();
			$form = new Form($this, "EditForm", $fields, new FieldList());
		}

		$form->addExtraClass('cms-edit-form center ss-tabset ' . $this->BaseCSSClasses());
		$form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));
		$form->setAttribute('data-pjax-fragment', 'CurrentForm');
		$this->extend('updateEditForm', $form);

		return $form;
	}


	public function SiteTreeAsUL() {
		$html = $this->getSiteTreeFor($this->stat('tree_class'), null, 'Children');
		$this->extend('updateSiteTreeAsUL', $html);
		return $html;
	}


	/**
	 * Get the form used to create a new provider
	 * 
	 * @return Form
	 */
	public function AddForm() {
		$classes = ClassInfo::subclassesFor(self::$tree_class);
		array_shift($classes);

		foreach ($classes as $key => $class) {
			if (!singleton($class)->canCreate())
				unset($classes[$key]);
		}

		$fields = new FieldList(
			new HiddenField("ParentID"),
			new HiddenField("Locale", 'Locale', i18n::get_locale()),
			new DropdownField("ProviderType", "", $classes)
		);

		$actions = new FieldList(
			FormAction::create("addprovider", _t('ExternalContent.CREATE', "Create"))
				->addExtraClass('ss-ui-action-constructive')->setAttribute('data-icon', 'accept')
				->setUseButtonTag(true)
		);



		return new Form($this, "AddForm", $fields, $actions);
	}

	/**
	 * Add a new provider (triggered by the ExternalContentAdmin_left template)
	 * 
	 * @return unknown_type
	 */
	public function addprovider() {
		// Providers are ALWAYS at the root
		$parent = 0;

		$name = (isset($_REQUEST['Name'])) ? basename($_REQUEST['Name']) : _t('ExternalContent.NEWCONNECTOR', "New Connector");

		$type = $_REQUEST['ProviderType'];
		$providerClasses = ClassInfo::subclassesFor(self::$tree_class);

		if (!in_array($type, $providerClasses)) {
			throw new Exception("Invalid connector type");
		}

		$parentObj = null;

		// Create object
		$record = new $type();
		$record->ParentID = $parent;
		$record->Name = $record->Title = $name;

		// if (isset($_REQUEST['returnID'])) {
		// 	return $p->ID;
		// } else {
		// 	return $this->returnItemToUser($p);
		// }

		try {
			$record->write();
		} catch(ValidationException $ex) {
			$form->sessionMessage($ex->getResult()->message(), 'bad');
			return $this->getResponseNegotiator()->respond($this->request);
		}

		$editController = singleton('CMSPageEditController');
		$editController->setCurrentPageID($record->ID);

		Session::set(
			"FormInfo.Form_EditForm.formError.message", 
			sprintf(_t('ExternalContent.SourceAdded', 'Successfully created %s'), $type)
		);
		Session::set("FormInfo.Form_EditForm.formError.type", 'good');

		$this->response->addHeader('X-Status', rawurlencode(_t('LeftAndMain.SAVEDUP', 'Saved.')));
		return $this->getResponseNegotiator()->respond($this->request);
		
		return $this->redirect(Controller::join_links($this->Link('show'), $record->ID));

	}

	/**
	 * Copied from AssetAdmin... 
	 * 
	 * @return Form
	 */
	function DeleteItemsForm() {
		$form = new Form(
						$this,
						'DeleteItemsForm',
						new FieldList(
								new LiteralField('SelectedPagesNote',
										sprintf('<p>%s</p>', _t('ExternalContentAdmin.SELECT_CONNECTORS', 'Select the connectors that you want to delete and then click the button below'))
								),
								new HiddenField('csvIDs')
						),
						new FieldList(
								new FormAction('deleteprovider', _t('ExternalContentAdmin.DELCONNECTORS', 'Delete the selected connectors'))
						)
		);

		$form->addExtraClass('actionparams');

		return $form;
	}

	/**
	 * Delete a folder
	 */
	public function deleteprovider() {
		$script = '';
		$ids = split(' *, *', $_REQUEST['csvIDs']);
		$script = '';

		if (!$ids)
			return false;

		foreach ($ids as $id) {
			if (is_numeric($id)) {
				$record = ExternalContent::getDataObjectFor($id);
				if ($record) {
					$script .= $this->deleteTreeNodeJS($record);
					$record->delete();
					$record->destroy();
				}
			}
		}

		$size = sizeof($ids);
		if ($size > 1) {
			$message = $size . ' ' . _t('AssetAdmin.FOLDERSDELETED', 'folders deleted.');
		} else {
			$message = $size . ' ' . _t('AssetAdmin.FOLDERDELETED', 'folder deleted.');
		}

		$script .= "statusMessage('$message');";
		echo $script;
	}


	public function getCMSTreeTitle(){
		return 'Connectors';
	}

	
	/**
	 * @return String HTML
	 */
	public function treeview($request) {
		return $this->renderWith($this->getTemplatesWithSuffix('_TreeView'));
	}



}

?>