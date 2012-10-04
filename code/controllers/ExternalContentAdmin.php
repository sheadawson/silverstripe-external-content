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
		Requirements::customCSS($this->generatePageIconsCss());
		Requirements::css(EXTERNALCONTENT . '/css/external-content-admin.css');
		Requirements::javascript(EXTERNALCONTENT . '/javascript/external-content-admin.js');
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
		$migrationTarget 		= isset($request['MigrationTarget']) ? $request['MigrationTarget'] : '';
		$fileMigrationTarget 	= isset($request['FileMigrationTarget']) ? $request['FileMigrationTarget'] : '';
		$includeSelected 		= isset($request['IncludeSelected']) ? $request['IncludeSelected'] : 0;
		$includeChildren 		= isset($request['IncludeChildren']) ? $request['IncludeChildren'] : 0;
		$duplicates 			= isset($request['DuplicateMethod']) ? $request['DuplicateMethod'] : ExternalContentTransformer::DS_OVERWRITE;
		$selected 				= isset($request['ID']) ? $request['ID'] : 0;

		if(!$selected){
			$messageType = 'bad';
			$message = _t('ExternalContent.NOITEMSELECTED', 'No item selected to import.');
		}

		if(!$migrationTarget && !$fileMigrationTarget){
			$messageType = 'bad';
			$message = _t('ExternalContent.NOTARGETSELECTED', 'No target to import to selected.');
		}

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

			$messageType = 'good';
			$message = _t('ExternalContent.CONTENTMIGRATED', 'Import Successful.');
		}

		Session::set("FormInfo.Form_EditForm.formError.message",$message);
		Session::set("FormInfo.Form_EditForm.formError.type", $messageType);

		return $this->getResponseNegotiator()->respond($this->request);	
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

				$migrateButton = FormAction::create('migrate', _t('ExternalContent.IMPORT', 'Start Importing'))
					->setAttribute('data-icon', 'arrow-circle-double')
					->setUseButtonTag(true);

				$fields->addFieldToTab('Root.Import', new LiteralField('MigrateActions', "<div class='Actions'>{$migrateButton->forTemplate()}</div>"));
			}

			$fields->push($hf = new HiddenField("ID"));
			$hf->setValue($id);

			$fields->push($hf = new HiddenField("Version"));
			$hf->setValue(1);

			$actions = new FieldList();

			$actions = CompositeField::create()->setTag('fieldset')->addExtraClass('ss-ui-buttonset');
			$actions = new FieldList($actions);
			
			// Only show save button if not 'assets' folder
			if ($record->canEdit()) {
				$actions->push(
					FormAction::create('save',_t('ExternalContent.SAVE','Save'))
						->addExtraClass('ss-ui-action-constructive')
						->setAttribute('data-icon', 'accept')
						->setUseButtonTag(true)
				);
			}

			if($isSource && $record->canDelete()){
				$actions->push(
					FormAction::create('delete',_t('ExternalContent.DELETE','Delete'))
						->addExtraClass('delete ss-ui-action-destructive')
						->setAttribute('data-icon', 'decline')
						->setUseButtonTag(true)
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

		$form = new Form($this, "AddForm", $fields, $actions);

		$form->addExtraClass('cms-edit-form ' . $this->BaseCSSClasses());
		$this->extend('updateEditForm', $form);

		return $form; 
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

		try {
			$record->write();
		} catch(ValidationException $ex) {
			$form->sessionMessage($ex->getResult()->message(), 'bad');
			return $this->getResponseNegotiator()->respond($this->request);
		}

		$this->setCurrentPageID($record->ID);

		Session::set("FormInfo.Form_EditForm.formError.type", 'good');
		Session::set(
			"FormInfo.Form_EditForm.formError.message", 
			sprintf(_t('ExternalContent.SourceAdded', 'Successfully created %s'), $type)
		);
		
		return $this->redirect($this->Link('show/' . $record->ID));
	}


	/**
	 * Delete a provider
	 */
	public function deleteprovider() {
		$ids = split(' *, *', $_REQUEST['csvIDs']);

		if (!$ids)
			return false;

		foreach ($ids as $id) {
			if (is_numeric($id)) {
				$record = ExternalContent::getDataObjectFor($id);
				if ($record) {
					$record->delete();
					$record->destroy();
				}
			}
		}

		$size = sizeof($ids);
		if ($size > 1) {
			$message = $size . ' ' . _t('ExternalContent.PROVIDERDELETED', 'provider deleted.');
		} else {
			$message = $size . ' ' . _t('ExternalContent.PROVIDERDELETED', 'provider deleted.');
		}

		Session::set("FormInfo.Form_EditForm.formError.type", 'good');
		Session::set(
			"FormInfo.Form_EditForm.formError.message", 
			$message
		);

		// TODO redirecting does not seem to behave the same here
		// should reload the form & tree live addprovider does...
		$this->setCurrentPageID('root'); 
		return $this->redirect($this->Link());
	}


	public function getCMSTreeTitle(){
		return 'Connectors';
	}


	public function SiteTreeAsUL() {
		$html = $this->getSiteTreeFor($this->stat('tree_class'), null, 'Children');
		$this->extend('updateSiteTreeAsUL', $html);
		return $html;
	}


	public function LinkTreeView() {
		return $this->Link('treeview');
	}

	
	/**
	 * @return String HTML
	 */
	public function treeview($request) {
		return $this->renderWith($this->getTemplatesWithSuffix('_TreeView'));
	}


 	/**
	 * Include CSS for page icons. We're not using the JSTree 'types' option
	 * because it causes too much performance overhead just to add some icons.
	 * 
	 * @return String CSS 
	 */
	public function generatePageIconsCss() {
		$css = ''; 
		
		$sourceClasses 	= ClassInfo::subclassesFor('ExternalContentSource');
		$itemClasses 	= ClassInfo::subclassesFor('ExternalContentItem');
		$classes 		= array_merge($sourceClasses, $itemClasses);
		
		foreach($classes as $class) {
			$obj = singleton($class); 
			$iconSpec = $obj->stat('icon'); 

			if(!$iconSpec) continue;

			// Legacy support: We no longer need separate icon definitions for folders etc.
			$iconFile = (is_array($iconSpec)) ? $iconSpec[0] : $iconSpec;

			// Legacy support: Add file extension if none exists
			if(!pathinfo($iconFile, PATHINFO_EXTENSION)) $iconFile .= '-file.gif';

			$iconPathInfo = pathinfo($iconFile); 
			
			// Base filename 
			$baseFilename = $iconPathInfo['dirname'] . '/' . $iconPathInfo['filename'];
			$fileExtension = $iconPathInfo['extension'];

			$selector = ".page-icon.class-$class, li.class-$class > a .jstree-pageicon";

			if(Director::fileExists($iconFile)) {
				$css .= "$selector { background: transparent url('$iconFile') 0 0 no-repeat; }\n";
			} else {
				// Support for more sophisticated rules, e.g. sprited icons
				$css .= "$selector { $iconFile }\n";
			}
			
		}

		$css .= "li.type-file > a .jstree-pageicon { background: transparent url('framework/admin/images/sitetree_ss_pageclass_icons_default.png') 0 0 no-repeat; }\n}";

		return $css;
	}


	/**
	 * Overridden as LeftAndMain::updatetreenodes assumes Sort field on data object
	 * @return String JSON
	 */
	public function updatetreenodes($request) {
		if(!$ids = $request->getVar('ids')){
			// TODO requests are coming through on delete to update the tree, but they don't have the ID!
			// Because the form has already been deleted
			return Convert::raw2json(array());
		}

		// TODO this does not take into consideration the children of this node, needs to be fixed in 
		// LeftAndMain::updatetreenodes too

		$data = array();
		$ids = explode(',', $ids);		

		foreach($ids as $id) {
			if($record = $this->getRecord($id)){
				$recordController = $this;
				$link = Controller::join_links($recordController->Link("show"), $record->ID);
				$html = LeftAndMain_TreeNode::create($record, $link, $this->isCurrentPage($record))->forTemplate() . '</li>';
				$data[$id] = array(
					'html' => $html, 
					'ParentID' => $record->ParentID
				);
			}
		}
		$this->response->addHeader('Content-Type', 'text/json');
		return Convert::raw2json($data);
	}

}

?>