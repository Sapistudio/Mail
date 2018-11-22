<?php
namespace SapiStudio\SapiMail\Imap\Search;

class Logical
{
	public function __construct(array $conditions)
    {
        foreach ($conditions as $condition) {
            $this->addCondition($condition);
        }
    }

    private function addCondition(ConditionInterface $condition)
    {
        $this->conditions[] = $condition;
    }

    public function toString()
    {
        $conditions = \array_map(function (ConditionInterface $condition) {
            return $condition->toString();
        }, $this->conditions);

        return \sprintf('( %s )', \implode(' OR ', $conditions));
    }
    
    public function all()
    {
        return 'ALL';
    }
}