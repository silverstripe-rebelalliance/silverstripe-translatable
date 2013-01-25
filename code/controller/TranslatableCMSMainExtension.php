<?php
/**
 * @package translatable
 */
class TranslatableCMSMainExtension extends Extension {

	static $collection_actions = array(
		'createtranslation',
	);

	function init() {
		// Ignore being called on LeftAndMain base class,
		// which is the case when requests are first routed through AdminRootController
		// as an intermediary rather than the endpoint controller
		if(!$this->owner->stat('tree_class')) return;

		Requirements::javascript('translatable/javascript/CMSMain.Translatable.js');
		Requirements::css('translatable/css/CMSMain.Translatable.css');
	}

	function beforeCallActionHandler($req, $action) {
		$id = $req->param('ItemID');

		if($req->requestVar("Locale")) {
			$this->owner->Locale = $req->requestVar("Locale");
		} elseif($req->requestVar("locale")) {
			$this->owner->Locale = $req->requestVar("locale");
		} else if($id && is_numeric($id)) {
			$record = DataObject::get_by_id($this->owner->stat('tree_class'), $id);
			if($record && $record->Locale) $this->owner->Locale = $record->Locale;
		} else {
			$this->owner->Locale = Translatable::default_locale();

			/* TODO: I don't think this is needed, but I don't really understand it's purpose
			if ($this->owner->class == 'CMSPagesController') {
				// the CMSPagesController always needs to have the locale set,
				// otherwise page editing will cause an extra
				// ajax request which looks weird due to multiple "loading"-flashes
				$getVars = $req->getVars();
				if(isset($getVars['url'])) unset($getVars['url']);
				return $this->owner->redirect(Controller::join_links(
					$this->owner->Link(),
					$req->param('Action'),
					$req->param('ID'),
					$req->param('OtherID'),
					'?' . http_build_query($getVars)
				));
			}
			*/
		}
		Translatable::set_current_locale($this->owner->Locale);

		// if a locale is set, it needs to match to the current record
		if($req->requestVar("Locale")) {
			$requestLocale = $req->requestVar("Locale");
		} else {
			$requestLocale = $req->requestVar("locale");
		}
		
		$page = $this->owner->currentPage();
		if(
			$requestLocale && $page && $page->hasExtension('Translatable') 
			&& $page->Locale != $requestLocale
			&& $req->latestParam('Action') != 'EditorToolbar'
		) {
			$transPage = $page->getTranslation($requestLocale);
			if($transPage) {
				Translatable::set_current_locale($transPage->Locale);
				// ?locale will automatically be added
				$this->owner->redirect($this->owner->Link('show', $transPage->ID));
				return false;
			} else if ($this->owner->class != 'CMSPagesController') {
				// If the record is not translated, redirect to pages overview
				$this->owner->redirect(Controller::join_links(
					singleton('CMSPagesController')->Link(),
					'?locale=' . $requestLocale
				));
				return false;
			}
		}

		// collect languages for TinyMCE spellchecker plugin.
		// see http://wiki.moxiecode.com/index.php/TinyMCE:Plugins/spellchecker
		$langName = i18n::get_locale_name($this->owner->Locale);
		HtmlEditorConfig::get('cms')->setOption(
			'spellchecker_languages',
			"+{$langName}={$this->owner->Locale}"
		);
	}
	
	function updateEditForm(&$form) {
		if($form->getName() == 'RootForm' && Object::has_extension('SiteConfig',"Translatable")) {
			$siteConfig = SiteConfig::current_site_config();
			$form->Fields()->push(new HiddenField('Locale','', $siteConfig->Locale));
		}
	}
	
	function updatePageOptions(&$fields) {
		$fields->push(new HiddenField("Locale", 'Locale', Translatable::get_current_locale()));
	}
	
	/**
	 * Create a new translation from an existing item, switch to this language and reload the tree.
	 */
	function createtranslation($data, $form) {
		$request = $this->owner->getRequest();

		// Protect against CSRF on destructive action
		if(!SecurityToken::inst()->checkRequest($request)) return $this->owner->httpError(400);

		$langCode = Convert::raw2sql($request->postVar('NewTransLang'));
		$record = $this->owner->getRecord($request->postVar('ID'));
		if(!$record) return $this->owner->httpError(404);
		
		$this->owner->Locale = $langCode;
		Translatable::set_current_locale($langCode);
		
		// Create a new record in the database - this is different
		// to the usual "create page" pattern of storing the record
		// in-memory until a "save" is performed by the user, mainly
		// to simplify things a bit.
		// @todo Allow in-memory creation of translations that don't 
		// persist in the database before the user requests it
		$translatedRecord = $record->createTranslation($langCode);

		$url = $this->owner->Link('show', $translatedRecord->ID);

		// set the X-Pjax header to Content, so that the whole admin panel will be refreshed
		$this->owner->getResponse()->addHeader('X-Pjax', 'Content');
		
		return $this->owner->redirect($url);
	}

	function updateLink(&$link) {
		if($this->owner->Locale) $link = Controller::join_links($link, '?locale=' . $this->owner->Locale);
	}

	function updateLinkWithSearch(&$link) {
		if($this->owner->Locale) $link = Controller::join_links($link, '?locale=' . $this->owner->Locale);	
	}

	function updateExtraTreeTools(&$html) {
		$html = $this->LangForm()->forTemplate() . $html;
	}

	function updateLinkPageAdd(&$link) {
		if($this->owner->Locale) $link = Controller::join_links($link, '?Locale=' . $this->owner->Locale);
	}
	
	/**
	 * Returns a form with all languages with languages already used appearing first.
	 * 
	 * @return Form
	 */
	function LangForm() {
		$member = Member::currentUser(); //check to see if the current user can switch langs or not
		if(Permission::checkMember($member, 'VIEW_LANGS')) {
			$field = new LanguageDropdownField(
				'Locale', 
				_t('CMSMain.LANGUAGEDROPDOWNLABEL', 'Language'), 
				array(), 
				'SiteTree', 
				'Locale-English',
				singleton('SiteTree')
			);
			$field->setValue(Translatable::get_current_locale());
        } else {
			// user doesn't have permission to switch langs 
			// so just show a string displaying current language
			$field = new LiteralField(
				'Locale', 
				i18n::get_locale_name( Translatable::get_current_locale())
			);
		}
		
		$form = new Form(
			$this->owner,
			'LangForm',
			new FieldList(
				$field
			),
			new FieldList(
				new FormAction('selectlang', _t('CMSMain_left.ss.GO','Go'))
			)
		);
		$form->unsetValidator();
		$form->addExtraClass('nostyle');
		
		return $form;
	}
	
	function selectlang($data, $form) {
		return $this->owner;
	}
	
	/**
	 * Determine if there are more than one languages in our site tree.
	 * 
	 * @return boolean
	 */
	function MultipleLanguages() {
		$langs = Translatable::get_existing_content_languages('SiteTree');

		return (count($langs) > 1);
	}
	
	/**
	 * @return boolean
	 */
	function IsTranslatableEnabled() {
		return Object::has_extension('SiteTree', 'Translatable');
	}
	
}