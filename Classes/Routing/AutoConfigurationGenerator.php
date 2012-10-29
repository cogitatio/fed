<?php
class Tx_Fed_Routing_AutoConfigurationGenerator {

	/**
	 * @var Tx_Flux_Service_Flexform
	 */
	protected $flexFormService;

	/**
	 * @var Tx_Extbase_Object_ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @var array
	 */
	protected $currentTableConfigurationArray = array();

	/**
	 * @var string
	 */
	protected $currentExtensionName = 'Fed';

	/**
	 * @var array
	 */
	protected $excludedArgumentTypes = array(
		'array'
	);

	/**
	 * CONSTRUCTOR
	 */
	public function __construct() {
		$this->objectManager = t3lib_div::makeInstance('Tx_Extbase_Object_ObjectManager');
		$this->flexFormService = $this->objectManager->get('Tx_Flux_Service_Flexform');
	}

	/**
	 * Builds automatic rules for every Extbase plugin's controllers
	 * in relation to the pages on which the plugins are inserted.
	 *
	 * Does this by looping through all configured Extbase plugins and
	 * checking those against the tt_content records currently active
	 * and prioritizes those records so that only the topmost active
	 * (depending on routing configuration) plugin on any one page is
	 * able to receive the default request arguments. This is done in
	 * order to prevent colissions.
	 *
	 * However, it is still possible to switch controllers in the rule
	 * that is build for your particular Controller - this only requires
	 * that you add this annotation to each Controller which should be
	 * able to include the "controller" and "action" arguments as segments
	 * in the nice URLs: @route NoMatch(NULL). This annotation is set
	 * on the class itself when it applies to the "controller" and
	 * "action" arguments; if it applies to a controller action argument
	 * then it must be placed in the parent method's annotations.
	 *
	 * @param array $params
	 * @param object $reference
	 */
	public function buildAutomaticRules($params, $reference) {
		$extensionsAndPluginNames = array();
		foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'] as $extensionName => $extensionConfiguration) {
			foreach ($extensionConfiguration['plugins'] as $pluginName => $pluginConfiguration) {
				$routable = FALSE;
				foreach ($pluginConfiguration['controllers'] as $controllerName => $controllerConfiguration) {
					if ($routable === TRUE) {
						break;
					}
					$controllerClassName = 'Tx_' . $extensionName . '_Controller_' . $controllerName . 'Controller';
					$controllerClassReflection = new ReflectionClass($controllerClassName);
					$controllerClassAnnotations = $this->getRoutingAnnotations($controllerClassReflection->getDocComment());
					if ($this->assertIsRoutable($controllerClassAnnotations) === FALSE && $routable === FALSE) {
						break;
					}
					foreach ($controllerConfiguration['actions'] as $actionName) {
						if (method_exists($controllerClassName, $actionName . 'Action') === FALSE) {
							continue;
						}
						$methodReflection = $controllerClassReflection->getMethod($actionName . 'Action');
						$methodAnnotations = $this->getRoutingAnnotations($methodReflection->getDocComment());
						if ($this->assertIsRoutable($methodAnnotations) === TRUE) {
							$routable = TRUE;
							break;
						}
					}
				}
				if ($routable === TRUE) {
					array_push($extensionsAndPluginNames, $extensionName . '->' . $pluginName);
					unset($pluginConfiguration);
				}
			}
			unset($extensionConfiguration);
		}
		if (isset($params['config']['fixedPostVars']) === FALSE || is_array($params['config']['fixedPostVars']) === FALSE) {
			$params['config']['fixedPostVars'] = array();
		}
		$definitions = $this->buildFixedPostVarsForExtensionsAndPluginNames($extensionsAndPluginNames);

			// note: foreach-style mapping because array_merge would re-index the numeric
			// indices which are page UIDs - so this would not suit the purpose of mapping
		foreach ($definitions as $pidOrName => $definitionOrMappingTarget) {
			$params['config']['fixedPostVars'][$pidOrName] = $definitionOrMappingTarget;
		}
		unset($reference);
		return $params['config'];
	}

	/**
	 * Builds and stores internally the fixed post var sets for all
	 * extensions and plugin names in $extensionsAndPluginNames
	 *
	 * @param array $extensionsAndPluginNames
	 * @return void
	 */
	protected function buildFixedPostVarsForExtensionsAndPluginNames($extensionsAndPluginNames) {
		$definitions = array();
		$pluginSignatures = array();
		foreach ($extensionsAndPluginNames as $extensionAndPluginName) {
			list ($extensionName, $pluginName) = explode('->', $extensionAndPluginName);
			$pluginSignature = strtolower(str_replace('_', '', $extensionName) . '_' . str_replace('_', '', $pluginName));
			$pluginSignatures[$extensionAndPluginName] = $pluginSignature;
		}
		$registeredPluginSignatureValues = array_values($pluginSignatures);
		foreach ($extensionsAndPluginNames as $extensionAndPluginName) {
			list ($extensionName, $pluginName) = explode('->', $extensionAndPluginName);
			$this->currentExtensionName = $extensionName;
				// Note: these next lines loads a copy of the TCA temporarily
			$_EXTKEY = t3lib_div::camelCaseToLowerCaseUnderscored($extensionName);
			$extensionTableConfigurationArrayDefinitionFile = t3lib_extMgm::extPath(strtolower($_EXTKEY), 'ext_tables.php');
			if (file_exists($extensionTableConfigurationArrayDefinitionFile)) {
				eval('?>' . file_get_contents($extensionTableConfigurationArrayDefinitionFile));
				$this->currentTableConfigurationArray = $TCA;
			} else {
				$this->currentTableConfigurationArray = array();
			}
			$pluginSignature = $pluginSignatures[$extensionAndPluginName];
			$urlPrefix = 'tx_' . $pluginSignature;
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$extensionName]['plugins'][$pluginName]['controllers'] as $controllerName => $controllerConfiguration) {
				$controllerClassName = 'Tx_' . $extensionName . '_Controller_' . $controllerName . 'Controller';
				$controllerClassReflection = new ReflectionClass($controllerClassName);
				$controllerClassAnnotations = $this->getRoutingAnnotations($controllerClassReflection->getDocComment());
				if ($this->assertIsRoutable($controllerClassAnnotations) === FALSE) {
					continue;
				}
				foreach ($controllerConfiguration['actions'] as $actionName) {
					if (method_exists($controllerClassName, $actionName . 'Action') === FALSE) {
						continue;
					}
					$identity = $pluginSignature . '_' . $controllerName . '_' . $actionName;
					$methodReflection = $controllerClassReflection->getMethod($actionName . 'Action');
					$annotations = $this->getRoutingAnnotations($methodReflection->getDocComment());
					if ($this->assertIsRoutable($annotations) === FALSE) {
						continue;
					}
					$arguments = $methodReflection->getParameters();
					$definitions[$identity] = array(
						$this->buildFixedPostVarsForController($urlPrefix, $controllerClassAnnotations),
						$this->buildFixedPostVarsForControllerAction($urlPrefix, $annotations),
					);
					foreach ($arguments as $argumentReflection) {
						$segment = $this->buildFixedPostVarsForControllerActionArgument($argumentReflection, $actionName, $urlPrefix);
						if ($segment !== NULL) {
							array_push($definitions[$identity], $segment);
						}
					}
					$pageUids = $this->getAllPAgeUidsWithPluginSignatureInColPosZeroTop($extensionName, $pluginName, $controllerName, $actionName, $registeredPluginSignatureValues);
					foreach ($pageUids as $pid) {
						$definitions[$pid] = $identity;
					}
				}
			}
		}
		return $definitions;
	}

	/**
	 * Assert wether this any one of this set of annotations disables routing.
	 *
	 * @param Tx_Fed_Routing_RoutingAnnotation[] $annotations
	 * @return boolean
	 */
	protected function assertIsRoutable(array $annotations) {
		foreach ($annotations as $annotation) {
			if ($annotation->assertRoutingDisabled() === TRUE) {
				return FALSE;
			}
		}
		return TRUE;
	}

	/**
	 * Assert the noMatch rule for this set of annotations. Last one has precedense.
	 *
	 * @param Tx_Fed_Routing_RoutingAnnotation[] $annotations
	 * @param string|NULL $argumentName
	 * @return string|NULL
	 */
	protected function assertNoMatchRule(array $annotations, $argumentName = NULL) {
		$rule = NULL;
		foreach ($annotations as $annotation) {
			if ($annotation->getNoMatchRule() !== NULL && $annotation->assertAppliesToVariable($argumentName)) {
				$rule = $annotation->getNoMatchRule();
			}
		}
		return $rule;
	}

	/**
	 * @param string $docComment
	 * @return Tx_Fed_Routing_RoutingAnnotation[]
	 */
	protected function getRoutingAnnotations($docComment) {
		$pattern = '/@route[\s]+(.[^\n]+)[\n]{1,1}/';
		$matches = array();
		$annotations = array();
		preg_match_all($pattern, $docComment, $matches);
		array_shift($matches);
		$annotationLines = array_shift($matches);
		foreach ($annotationLines as $matchedPattern) {
			/** @var $annotation Tx_Fed_Routing_RoutingAnnotation */
			$annotation = $this->objectManager->create('Tx_Fed_Routing_RoutingAnnotation');
			$annotation->setMatchedPattern($matchedPattern);
			array_push($annotations, $annotation);
		}
		return $annotations;
	}

	/**
	 * Get an array of UIDs of all pages on which $pluginSignature is the first
	 * Extbase plugin in colPos zero.
	 *
	 * @param string $extensionName
	 * @param string $pluginName
	 * @param string $controllerName
	 * @param string $actionName
	 * @param array $registeredPluginSignatures
	 * @return array
	 */
	protected function getAllPAgeUidsWithPluginSignatureInColPosZeroTop($extensionName, $pluginName, $controllerName, $actionName, $registeredExtbasePluginSignatures) {
		$pluginSignature = strtolower(str_replace('_', '', $extensionName) . '_' . str_replace('_', '', $pluginName));
		$clause = "t.deleted = '0' AND t.hidden = '0' AND t.starttime <= '" . time() . "' AND (t.endtime >= '" . time() . "' OR t.endtime = '0') AND p.deleted = '0' AND p.uid = t.pid";
		$orderedPageSignatures = array();
		$contentRecords = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('t.pid, t.CType, t.list_type, t.pi_flexform', 'tt_content t, pages p', $clause, 'p.pid, t.sorting DESC');
		$pageUids = array();
		foreach ($contentRecords as $contentRecord) {
			$pid = $contentRecord['pid'];
			$signature = $contentRecord['list_type'] ? $contentRecord['list_type'] : $contentRecord['CType'];
			if (in_array($signature, $registeredExtbasePluginSignatures)) {
				$orderedPageSignatures[$pid] = array(
					$signature,
					$contentRecord['pi_flexform']
				);
			}
		}
		foreach ($orderedPageSignatures as $pid => $signatureAndFlexform) {
			list ($signature, $flexFormSource) = $signatureAndFlexform;
			if ($flexFormSource) {
				$decoded = $this->flexFormService->convertFlexFormContentToArray($flexFormSource);
			} else {
				$decoded = NULL;
			}
			if (isset($decoded['switchableControllerActions']) && strpos($decoded['switchableControllerActions'], '->') !== FALSE) {
				list ($contentRecordController, $contentRecordControllerAction) = explode('->', $decoded['switchableControllerActions']);
			} else {
				reset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$extensionName]['plugins'][$pluginName]['controllers']);
				$contentRecordController = key($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$extensionName]['plugins'][$pluginName]['controllers']);
				$contentRecordControllerAction = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase']['extensions'][$extensionName]['plugins'][$pluginName]['controllers'][$contentRecordController]['actions'][0];
			}
			if ($signature === $pluginSignature && $contentRecordController === $controllerName && $contentRecordControllerAction === $actionName) {
				array_push($pageUids, $pid);
			}
		}
		return $pageUids;
	}

	/**
	 * @param string $urlPrefix
	 * @param Tx_Fed_Routing_RoutingAnnotation[] $annotations
	 * @return array
	 */
	protected function buildFixedPostVarsForController($urlPrefix, $annotations) {
		$definition = array(
			'GETvar' => $urlPrefix . '[controller]',
		);
		$noMatchRule = $this->assertNoMatchRule($annotations);
		if ($noMatchRule !== NULL) {
			$definition['noMatch'] = $noMatchRule;
		}
		return $definition;
	}

	/**
	 * @param string $urlPrefix
	 * @param Tx_Fed_Routing_RoutingAnnotation[] $annotations
	 * @return array
	 */
	protected function buildFixedPostVarsForControllerAction($urlPrefix, $annotations) {
		$definition = array(
			'GETvar' => $urlPrefix . '[action]',
		);
		$noMatchRule = $this->assertNoMatchRule($annotations);
		if ($noMatchRule !== NULL) {
			$definition['noMatch'] = $noMatchRule;
		}
		return $definition;
	}

	/**
	 * @param ReflectionParameter $argument
	 * @param string $actionName
	 * @param string $urlPrefix
	 * @return array
	 */
	protected function buildFixedPostVarsForControllerActionArgument(ReflectionParameter $argument, $actionName, $urlPrefix) {
		$argumentName = $argument->getName();
		$annotations = $this->getRoutingAnnotations($argument->getDeclaringFunction()->getDocComment());
		$definition = array(
			'GETvar' => $urlPrefix . '[' . $argumentName . ']',
		);
		$noMatchRule = $this->assertNoMatchRule($annotations, $argumentName);
		$definition['noMatch'] = $noMatchRule;

		$docComment = $argument->getDeclaringFunction()->getDocComment();
		$matches = array();
		preg_match('/@param[\s]+([a-zA-Z_0-9\\^\s]+)[\s]+\$' . $argumentName . '/', $docComment, $matches);
		$argumentDataType = trim($matches[1]);
		if (in_array($argumentDataType, $this->excludedArgumentTypes) === TRUE) {
			return NULL;
		}
		if (class_exists($argumentDataType) && in_array('Tx_Extbase_DomainObject_DomainObjectInterface', class_implements($argumentDataType))) {
			$tableName = strtolower($argumentDataType);
			if (isset($this->currentTableConfigurationArray[$tableName]) === TRUE) {
				$_EXTKEY = t3lib_div::camelCaseToLowerCaseUnderscored($this->currentExtensionName);
				$TCA[$tableName] = $this->currentTableConfigurationArray;
				$extensionConfigurationFile = t3lib_extMgm::extPath($_EXTKEY, 'ext_tables.php');
				if (file_exists($extensionConfigurationFile)) {
					eval('?>' . file_get_contents($extensionConfigurationFile));
				}
				$dynamicConfigurationFile = $TCA[$tableName]['ctrl']['dynamicConfigFile'];
				if (file_exists($dynamicConfigurationFile)) {
					eval('?>' . file_get_contents($dynamicConfigurationFile));
				}
				$definition['lookUpTable'] = array(
					'table' => $tableName,
					'id_field' => 'uid',
					'alias_field' => $TCA[$tableName]['ctrl']['label'],
					'addWhereClause' => ' AND NOT deleted',
					'useUniqueCache' => 1,
					'useUniqueCache_conf' => array(
						'strtolower' => 1,
						'spaceCharacter' => '-'
					)
				);
			}
			#var_dump($definition['noMatch']);
		}
		return $definition;
	}

	/**
	 * @param array $contentRecord
	 * @return array|NULL
	 */
	protected function getSetupForRecord($contentRecord) {
		$typoScriptDefinition = $GLOBALS['TSFE']->tmpl->setup['tt_content.'];
		if ($contentRecord['list_type'] && isset($typoScriptDefinition['list.']['20.'][$contentRecord['list_type'] . '.'])) {
			$setup = $typoScriptDefinition['list.']['20.'][$contentRecord['list_type'] . '.'];
		} elseif ($contentRecord['CType']) {
			$setup = $typoScriptDefinition[$contentRecord['CType'] . '.'];
		} else {
			$setup = NULL;
		}
		return $setup;
	}

	/**
	 * Asserts wether or not a content record renders an Extbase plugin
	 *
	 * @param array $contentRecord
	 * @param array|NULL $setup
	 * @return boolean
	 */
	protected function assertIsExtbasePlugin($contentRecord, $setup = NULL) {
		if ($setup === NULL) {
			$setup = $this->getSetupForRecord($contentRecord);
		}
		if ($setup === NULL) {
			return FALSE;
		}
		if ($this->assertArrayContainsValueRecursive($setup, $this->extbaseUserFunctionIdentifiers)) {
			return TRUE;
		}
		return FALSE;
	}

	/**
	 * @param array $array
	 * @param mixed $value
	 * @return mixed
	 */
	protected function getArrayValueRecursive(array $array, $value) {
		foreach ($array as $key => $member) {
			if (is_array($member) && $this->getArrayValueRecursive($member, $value)) {
				return $member;
			} elseif (is_array($value) && in_array($member, $value)) {
				return $member;
			} elseif ($key == $value) {
				return $member;
			}
		}
		return NULL;
	}

	/**
	 * @param array $array
	 * @param mixed $value
	 * @return boolean
	 */
	protected function assertArrayContainsValueRecursive(array $array, $value) {
		foreach ($array as $member) {
			if (is_array($member) && $this->assertArrayContainsValueRecursive($member, $value)) {
				return $member;
			} elseif (is_array($value) && in_array($member, $value)) {
				return $member;
			} elseif ($member == $value) {
				return $member;
			}
		}
		return FALSE;
	}

}