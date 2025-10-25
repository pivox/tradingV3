<?php

declare(strict_types=1);

namespace App\Contract\Provider\Dto;

/**
 * Classe de base pour tous les DTOs
 */
abstract class BaseDto
{
    public function toArray(): array
    {
        $result = [];
        $reflection = new \ReflectionClass($this);
        
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($this);
            
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('c');
            } elseif ($value instanceof BaseDto) {
                $value = $value->toArray();
            }
            
            $result[$property->getName()] = $value;
        }
        
        return $result;
    }
}


