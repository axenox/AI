<?php
namespace axenox\GenAI\AI\Agents;

use axenox\GenAI\Common\AiResponse;
use axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery;
use axenox\GenAI\DataTypes\AiMessageTypeDataType;
use axenox\GenAI\Exceptions\AiConceptIncompleteError;
use exface\Core\CommonLogic\Traits\AliasTrait;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use axenox\GenAI\Factories\AiFactory;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataConnectionFactory;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiResponseInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use axenox\GenAI\Interfaces\AiQueryInterface;
use exface\Core\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Templates\BracketHashStringTemplateRenderer;
use exface\Core\Templates\Placeholders\AppPlaceholders;
use exface\Core\Templates\Placeholders\ConfigPlaceholders;
use exface\Core\Templates\Placeholders\DataRowPlaceholders;
use exface\Core\Templates\Placeholders\FormulaPlaceholders;
use axenox\GenAI\Exceptions\AiConversationNotFoundError;

/**
 * Generic chat assistant with configurable system prompt
 * 
 * ## Examples
 * 
 * ```
 * {
 *   "system_prompt": "
 *      You are a helpful assistant, who will answer questions about the structure of the following database. 
 *      Here is the DB schema in DBML: \n\n[#metamodel_dbml#]
 *      Answer using the following locale \"[#=User('LOCALE')#]\"
 *   ",
 *   "system_concepts": {
 *     "metamodel_bmdb": {
 *       "class": "\\exface\\Core\\AI\\Concepts\\MetamodelDbmlConcept",
 *       "object_filters": {
 *         "operator": "AND",
 *         "conditions": [
 *           {"expression": "APP__ALIAS", "comparator": "==", "value": "exface.Core"}
 *         ]
 *       }
 *     }
 *   }
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 */
class GenericAssistant implements AiAgentInterface
{
    use ImportUxonObjectTrait;

    use AliasTrait;

    private $workbench = null;

    private $systemPrompt = null;
    private $temperature = null;

    private $systemPromptRendered = null;

    private $conceptConfig = [];

    private $dataConnectionAlias = null;

    private $dataConnection = null;

    private $name = null;

    private $selector = null;

    private $agentDataSheet = null;
    private $responseJsonSchema = null;

    private $responseAnswerPath = null;

    private $responseTitlePath = null;

    

    /**
     * 
     * @param \exface\Core\Interfaces\Selectors\AiAgentSelectorInterface $selector
     * @param \exface\Core\CommonLogic\UxonObject|null $uxon
     */
    public function __construct(AiAgentSelectorInterface $selector, UxonObject $uxon = null)
    {
        $this->workbench = $selector->getWorkbench();
        $this->selector = $selector;
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
    }

    public function handle(AiPromptInterface $prompt) : AiResponseInterface
    {
        $userPromt = $prompt->getUserPrompt();
        try {
            $this->setInstructions($this->getSystemPrompt($prompt));
        } catch (AiConceptIncompleteError $e) {
            throw $e;
            /* TODO handle different errors differently
            $this->workbench->getLogger()->logException($e);
            return $this->createResponseUnavailable('Error contacting the assistant', $prompt, $e);
            */
        }
        
        $query = new OpenAiApiDataQuery($this->workbench);
        $query->setSystemPrompt($this->systemPrompt);
        $query->setTemperature($this->temperature);
        $query->appendMessage($userPromt);
        if (null !== $val = $prompt->getConversationUid()) 
            $query->setConversationUid($val);

        if($this->hasJsonSchema())
            $query->setResponseJsonSchema($this->getResponseJsonSchema());

        $performedQuery = $this->getConnection()->query($query);
        $conversationId = $this->saveConversation($prompt, $performedQuery);

        return $this->parseDataQueryResponse($prompt, $performedQuery, $conversationId);
    }

    public function saveConversation(AiPromptInterface $prompt, AiQueryInterface $query) : string
    {
        $transaction = $this->workbench->data()->startTransaction();
        $sequenceNumber = $query->getSequenceNumber();
        try {
            $conversationId = $prompt->getConversationUid();
            $message = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_MESSAGE');
            if($conversationId === null) {
                $conversation = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
                $row = [
                    'AI_AGENT' => $this->getUid(),
                    'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                    'TITLE' => $this->getTitle($query),
                    'DATA' => $prompt->getInputData()->exportUxonObject()->toJson()
                ];
                if ($prompt->hasMetaObject()) {
                    $row['META_OBJECT'] = $prompt->getMetaObject()->getId();
                }
                if ($prompt->isTriggeredOnPage()) {
                    $row['PAGE'] = $prompt->getPageTriggeredOn()->getUid();
                }
                $conversation->addRow($row);
                $conversation->dataCreate(false,$transaction);
                $conversationId = $conversation->getUidColumn()->getValue(0);

                $message->addRow([
                    'AI_CONVERSATION' => $conversationId,
                    'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                    'ROLE'=> AiMessageTypeDataType::SYSTEM,
                    'MESSAGE'=> $this->systemPrompt,
                    'SEQUENCE_NUMBER' => $sequenceNumber++
                ]);
            }
            else{
                $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_CONVERSATION');
                $ds->getFilters()->addConditionFromAttribute($ds->getMetaObject()->getUidAttribute(), $conversationId, ComparatorDataType::EQUALS);
                $ds->getColumns()->addFromAttributeGroup($ds->getMetaObject()->getAttributes());
                $ds->dataRead();
                if($ds->isEmpty()){
                    throw new AiConversationNotFoundError("Ai Conversation '$conversationId' not found");
                }
            }

            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE'=> AiMessageTypeDataType::USER,
                'MESSAGE'=> $query->getUserPrompt(),
                'SEQUENCE_NUMBER' => $sequenceNumber++
            ]);
            
            $message->addRow([
                'AI_CONVERSATION' => $conversationId,
                'USER' => $this->workbench->getSecurity()->getAuthenticatedUser()->getUid(),
                'ROLE'=> AiMessageTypeDataType::ASSISTANT,
                'MESSAGE'=> $this->getAnswer($query),
                'DATA' => $query->getFullAnswer(),
                'SEQUENCE_NUMBER' => $sequenceNumber,
                'TOKENS_COMPLETION' => $query->getTokensInAnswer(),
                'TOKENS_PROMPT' => $query->getTokensInPrompt(),
                'COST_PER_M_TOKENS'=> $query->getCostPerMTokens(),
                'COST' => ($query->getTokensInPrompt() + $query->getTokensInAnswer()) * $query->getCostPerMTokens() * 0.000001,
                'FINISH_REASON' => $query->getFinishReason()
            ]);
            $message->dataCreate(false, $transaction);
            
            $transaction->commit();
        } catch(\Throwable $e){
            $transaction->rollback();
            $this->workbench->getLogger()->logException($e);
        }
        return $conversationId;
    }
    /**
     * AI concepts to be used in the system prompt
     * 
     * Each concept is basically a plugin, that generates part of the system prompt. You can use it anywhere in your
     * prompt via placeholder
     * 
     * @uxon-property concepts
     * @uxon-type \axenox\GenAI\Common\AbstractConcept
     * @uxon-template {"metamodel_bmdb": {"class": "\\exface\\Core\\AI\\Concepts\\MetamodelDbmlConcept"}}
     * 
     * @param \exface\Core\CommonLogic\UxonObject $arrayOfConcepts
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setConcepts(UxonObject $arrayOfConcepts) : AiAgentInterface
    {
        $this->concepts = null;
        $this->conceptConfig = $arrayOfConcepts;
        return $this;
    }

    /**
     * 
     * @return \axenox\GenAI\Interfaces\AiConceptInterface[]
     */
    protected function getConcepts(AiPromptInterface $prompt, BracketHashStringTemplateRenderer $configRenderer) : array
    {
        $concepts = [];
        foreach ($this->conceptConfig as $placeholder => $uxon) {
            $json = $uxon->toJson();
            $json = $configRenderer->render($json);
            $concepts[] = AiFactory::createConceptFromUxon($this->workbench,$placeholder, $prompt, UxonObject::fromJson($json));
        }
        return $concepts;
    }

    /**
     * An introduction to explain the LLM, what the assistant is supposed to do.
     * 
     * ## Available placeholders
     * 
     * - `[#~app:#]` - get properties of the app, where the assistant is called from: e.g. `[#~app:alias#]`
     * - `[#~input:#]` - access the first row of the input data (e.g. data sent by the AIChat widget)
     * - `[#~config:#]`
     * 
     * @uxon-property instructions
     * @uxon-type string
     * @uxon-template You are a helpful assistant, who will answer questions about the structure of the following database. Here is the DB schema in DBML: \n\n[#metamodel_dbml#] \n\nAnswer using the following locale [#=User('LOCALE')#]
     * 
     * @param string $text
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setInstructions(string $text) : AiAgentInterface
    {
        $this->systemPrompt = $text;
        return $this;
    }
    //Adding Set Temperature
    protected function setTemperature(float $t): AiAgentInterface
    {
        $this->temperature = $t;
        return $this;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $promt
     * @return string
     */
    protected function getSystemPrompt(AiPromptInterface $prompt) : string
    {
        if ($this->systemPromptRendered === null) {
            $renderer = new BracketHashStringTemplateRenderer($this->workbench);
            $renderer->addPlaceholder(new FormulaPlaceholders($this->workbench, null, null, '='));
            $renderer->addPlaceholder(resolver: new ConfigPlaceholders($this->workbench, '~config:'));
            if (null !== $app = $this->getApp($prompt)) {
                $renderer->addPlaceholder(new AppPlaceholders($app, '~app:'));
            }
            if ($prompt->hasInputData()) {
                $renderer->addPlaceholder(new DataRowPlaceholders($prompt->getInputData(), 0, '~input:'));
            }
            
            foreach ($this->getConcepts($prompt, $renderer) as $concept) {
                $renderer->addPlaceholder($concept);
            }
            
            try {
                $this->systemPromptRendered = $renderer->render($this->systemPrompt ?? '');
            } catch (\Throwable $e) {
                throw new AiConceptIncompleteError('Cannot apply AI concepts. ' . $e->getMessage(), null, $e);
            }
        }
        return $this->systemPromptRendered;
    }

    protected function getApp(AiPromptInterface $prompt) : ?AppInterface
    {
        $app = null;
        if ($prompt->isTriggeredOnPage()) {
            $app = $prompt->getPageTriggeredOn()->getApp();
        }
        // TODO determine the app from input data?
        return $app;
    }

    /**
     * 
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        $uxon = new UxonObject();
        // TODO
        return $uxon;
    } 

    /**
     * 
     * @return \exface\Core\Interfaces\DataSources\DataConnectionInterface
     */
    protected function getConnection() : DataConnectionInterface
    {
        if ($this->dataConnection === null) {
            $this->dataConnection = DataConnectionFactory::createFromModel($this->workbench, $this->dataConnectionAlias);
        }
        return $this->dataConnection;
    }
    
    /**
     * 
     * @param string $selector
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setDataConnectionAlias(string $selector) : AiAgentInterface
    {
        $this->dataConnectionAlias = $selector;
        return $this;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param \axenox\GenAI\Common\DataQueries\OpenAiApiDataQuery $query
     * @return \axenox\GenAI\Common\AiResponse
     */
    protected function parseDataQueryResponse(AiPromptInterface $prompt, OpenAiApiDataQuery $query, string $conversationId) : AiResponse
    {
        return new AiResponse($prompt, $this->getAnswer($query), $conversationId);
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @return string
     */
    protected function getAnswer(AiQueryInterface $query) : string
    {
        if ($this->hasJsonSchema()) {
            $json = $query->getAnswerJson();
            $answer = ArrayDataType::filterJsonPath($json, $this->getResponseAnswerPath())[0];
        } else {
            $answer = $query->getFullAnswer();
        }
        return $answer;
    }

    /**
     * 
     * @param \axenox\GenAI\Interfaces\AiQueryInterface $query
     * @return string
     */
    protected function getTitle(AiQueryInterface $query) : string
    {
        if ($this->hasJsonSchema()) {
            $json = $query->getAnswerJson();
            $title = ArrayDataType::filterJsonPath($json, $this->getResponseTitlePath())[0];
        } else {
            $title = StringDataType::truncate($query->getUserPrompt(), 50, true, true, true);
        }
        return $title;
    }

    /**
     * 
     * @param string $message
     * @param \axenox\GenAI\Interfaces\AiPromptInterface $prompt
     * @param mixed $e
     * @return AiResponse
     */
    protected function createResponseUnavailable(string $message, AiPromptInterface $prompt, ?\Throwable $e = null)
    {
        return new AiResponse($prompt, $message);
    }

    /**
     * 
     * @param string $alias
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setAlias(string $alias) : AiAgentInterface
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * 
     * @return \exface\Core\Interfaces\Selectors\AliasSelectorInterface
     */
    public function getSelector() : AliasSelectorInterface
    {
        return $this->selector;
    }

    /**
     * 
     * @param string $name
     * @return \axenox\GenAI\Interfaces\AiAgentInterface
     */
    protected function setName(string $name) : AiAgentInterface
    {
        $this->name = $name;
        return $this;
    }

    protected function getModelData() : DataSheetInterface
    {
        if ($this->agentDataSheet === null) {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.GenAI.AI_AGENT');
            $sheet->getColumns()->addFromSystemAttributes();
            $sheet->getColumns()->addMultiple([
                'NAME'
            ]);
            $sheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $this->getSelector()->toString(), ComparatorDataType::EQUALS);
            $sheet->dataRead();
            $this->agentDataSheet = $sheet;
        }
        return $this->agentDataSheet;
    }

    public function getUid() : string
    {
        return $this->getModelData()->getCellValue('UID', 0);
    }

    public function getName() : string
    {
        return $this->getModelData()->getCellValue('NAME', 0);
    }

    protected function getResponseJsonSchema() : ?array
    {
        return $this->responseJsonSchema;
    }

    private function hasJsonSchema() : bool
    {        
        return $this->responseJsonSchema !== null;
    }

    /**
     * Summary of setResponseJsonSchema
     * @uxon-property response_json_schema 
     * @uxon-type object
     * @uxon-template {"type":"object","properties":{"title":{"type":"string","description":"Summary of the conversation"},"text":{"type":"string","description":"Your answer as markdown"}},"additionalProperties":false,"required":["title"]}
     * @param \exface\Core\CommonLogic\UxonObject $uxon
     * @return static
     */
    protected function setResponseJsonSchema(UxonObject $uxon)
    {
        $this->responseJsonSchema = $uxon->toArray();
        return $this;
    }

    /**
     * The JSONPath to the response message if a JSON schema is used by this assistant
     * 
     * @uxon-property response_answer_path
     * @uxon-type string
     * @uxon-template $.text
     * 
     * @param string $jsonPath
     * @return \axenox\GenAI\AI\Agents\GenericAssistant
     */
    protected function setResponseAnswerPath(string $jsonPath) : GenericAssistant
    {
        $this->responseAnswerPath = $jsonPath;
        return $this;
    } 

    protected function getResponseAnswerPath() : ?string
    {
        return $this->responseAnswerPath;
    }

    /**
     * The JSONPath to the conversation title in the response JSON if a JSON schema is used by this assistant
     * 
     * @uxon-property response_title_path
     * @uxon-type string
     * @uxon-template $.title
     * 
     * @param string $jsonPath
     * @return \axenox\GenAI\AI\Agents\GenericAssistant
     */
    protected function setResponseTitlePath(string $jsonPath) : GenericAssistant
    {
        $this->responseTitlePath = $jsonPath;
        return $this;
    } 

    protected function getResponseTitlePath() : ?string
    {
        return $this->responseTitlePath;
    }
}