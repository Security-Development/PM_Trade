<?php
/**
 * @name TradePlugin
 * @author HANA
 * @main TradePlugin\TradePlugin
 * @version 0.1.0
 * @api 3.10.0
 */

 namespace TradePlugin;

 use pocketmine\{
   plugin\PluginBase,
   event\Listener,
   level\Level,
   item\Item,
   block\Block,
   math\Vector3,
   Player,
   Server,
   entity\Entity,
   event\player\PlayerJoinEvent,
   inventory\BaseInventory,
   nbt\NetworkLittleEndianNBTStream,
   inventory\ContainerInventory,
   block\BlockFactory,
   network\mcpe\protocol\BlockActorDataPacket,
   network\mcpe\protocol\ContainerClosePacket,
   network\mcpe\protocol\ContainerOpenPacket,
   network\mcpe\protocol\types\WindowTypes,
   network\mcpe\protocol\UpdateBlockPacket,
   event\server\DataPacketReceiveEvent,
   form\Form,
   entity\Human,
   command\PluginCommand,
   command\Command,
   command\CommandSender,
   utils\Config,
   event\entity\EntityDamageEvent,
   event\entity\EntityDamageByEntityEvent,
   event\inventory\InventoryTransactionEvent,
   network\mcpe\protocol\types\ContainerIds,
   scheduler\Task,
   inventory\transaction\action\SlotChangeAction,
   scheduler\ClosureTask
 };

 use pocketmine\nbt\tag\{
   CompoundTag,
   DoubleTag,
   FloatTag,
   ListTag,
   ShortTag,
   StringTag
 };


 class TradePlugin extends PluginBase
 {

   public static $data, $db;

   public static $sub = array();

   public static $instance;


   function onEnable() :void
   {
     Server::getInstance()->getPluginManager()->registerEvents(new TradeEvent($this), $this);

     $cmd = new PluginCommand('trade',$this);
     $cmd->setDescription('Trade Command');

     $this->getServer()->getCommandMap()->register('trade', $cmd);

     self::$data = new Config($this->getDataFolder().'TradeList.yml', Config::YAML);
     self::$db = self::$data->getAll();

   }

    function onLoad() : void
   {

     self::$instance = $this;
     Entity::registerEntity(TradeNPC::class, true, ['TradeNpc']);

   }

   function OpenUI1(Player $player)
   {
     $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
       $player->sendForm(new subUI());
     }), 20 * 0.5);
   }

   function onCommand(CommandSender $sender, Command $command, $lable, array $args) :bool
   {

     $cmd = $command->getName();

     if($cmd == 'trade')
     {

       if(!$sender->isOp()) return true;

       if($args[0] ?? $sender->sendForm(new TradeMainUI()));

     }

     return true;

   }

 }

 class TradeEvent implements Listener
 {

   public $item = array();

   const NAME = "< Trade Shop >";

   public static function TradeOpen(Player $player): void
   {

     for($i=0; $i<=35; $i++)
     {
     TradePlugin::$db['인벤'][$player->getName()][$i] = $player->getInventory()->getItem($i)->jsonSerialize();
     TradePlugin::$data->setAll(TradePlugin::$db);
     TradePlugin::$data->save();
     $player->getInventory()->setItem($i, Item::get(Item::AIR));
    }

     $player->addWindow(new TradeInventory(new TradeEvent(), [], $player->asVector3(), 5, self::NAME));

   }

   function TradeListOpen(Player $player): void
   {

     for($i=0; $i<=35; $i++)
     {
     TradePlugin::$db['인벤'][$player->getName()][$i] = $player->getInventory()->getItem($i)->jsonSerialize();
     TradePlugin::$data->setAll(TradePlugin::$db);
     TradePlugin::$data->save();
     $player->getInventory()->setItem($i, Item::get(Item::AIR));
    }

     $player->addWindow(new TradeListInventory($this, [], $player->asVector3(), 27, self::NAME));
   }

   function onJoin(PlayerJoinEvent $ev)
   {
     $player = $ev->getPlayer();
     $player->sendMessage("Trade 플러그인 제작자 : 하나 \n2차수정 및 배포 와 양도 금지");

     if(isset(TradePlugin::$db['인벤'][$player->getName()]))
     {
       for($i=0; $i<=35; $i++)
       {
         if(isset(TradePlugin::$db['인벤'][$player->getName()][$i]))
         {
         $item = Item::jsonDeserialize(TradePlugin::$db['인벤'][$player->getName()][$i]);
         $player->getInventory()->setItem($i, $item);
         }
       }
       unset(TradePlugin::$db['인벤'][$player->getName()]);
       TradePlugin::$data->setAll(TradePlugin::$db);
       TradePlugin::$data->save();
     }
   }

   function onHit(EntityDamageEvent $ev)
   {
     $entity = $ev->getEntity();

     if(!$entity instanceof TradeNPC) return true;

     if($ev instanceof EntityDamageByEntityEvent)
     {

       $damager = $ev->getDamager();

       if($damager instanceof Player)
       {
         $ev->setCancelled();

         if(isset(TradePlugin::$db[$entity->getNameTag()]))
         {
           TradePlugin::$db['trade'][$damager->getName()] = $entity->getNameTag();
           $this->TradeListOpen($damager);
         }

         if(isset(TradePlugin::$db[$damager->getName()]['all']))
         {

         TradePlugin::$db[$entity->getNameTag()][] = [
           'need' => TradePlugin::$db[$damager->getName()]['all']['item1'],
           'get' => TradePlugin::$db[$damager->getName()]['all']['item2']
         ];
         unset(TradePlugin::$db[$damager->getName()]['all']);
         unset(TradePlugin::$sub[$damager->getName()]['item1']);
         unset(TradePlugin::$sub[$damager->getName()]['item2']);
         TradePlugin::$data->setAll(TradePlugin::$db);
         TradePlugin::$data->save();
         $damager->sendMessage('You have successfully added an exchange item.');
         }
       }

     }

   }

   /*@앨빈님 블로그 참고 */
   function onCloseInventory(DataPacketReceiveEvent $ev)
   {
     $pl = $ev->getPlayer();
     $pk = $ev->getPacket();
     if($pk instanceof ContainerClosePacket)
     {
       $inv = $pl->getWindow($pk->windowId);// 앨빈님 블로그
       if($inv instanceof TradeListInventory or $inv instanceof TradeInventory)
       {
         $pk= new ContainerClosePacket();
         $pk->windowId = $pl->getWindowId($inv);
         $pl->dataPacket($pk);
       }
     }
   }

   function onInv(InventoryTransactionEvent $ev)
   {

     $trc = $ev->getTransaction();
     $player = $trc->getSource();

     if(!$player instanceof Player)return true;
     foreach($trc->getInventories() as $inventory)
     {

       if($inventory instanceof TradeListInventory)
       {

         $max = [];
         foreach($trc->getActions() as $action)
         {
           //$max[] = (int) $action->getSlot();

           if($action instanceof SlotChangeAction)
             if($action->getInventory() instanceof TradeListInventory)
             {
             $max[] = (int) $action->getSlot();
           }
       }

       $ev->setCancelled();

       $need = Item::jsonDeserialize(TradePlugin::$db[TradePlugin::$db['trade'][$player->getName()]][max($max)]['need']);
       $get = Item::jsonDeserialize(TradePlugin::$db[TradePlugin::$db['trade'][$player->getName()]][max($max)]['get']);
       TradePlugin::$db[$player->getName()]['item1'] = $need;
       TradePlugin::$db[$player->getName()]['item2'] = $get;

       $inventory->onClose($player);

       TradePlugin::$data->setAll(TradePlugin::$db);
       TradePlugin::$data->save();

       //$this->TradeOpen($player);
       TradePlugin::$instance->OpenUI1($player);
     }

   }

 }

 function onTrade(InventoryTransactionEvent $ev)
 {

   $trc = $ev->getTransaction();
   $player = $trc->getSource();

   if(!$player instanceof Player)return true;
   foreach($trc->getInventories() as $inventory)
   {

     if($inventory instanceof TradeInventory)
     {

       $number = 0;

       foreach($trc->getActions() as $action)
       {
         $item = $action->getSourceItem();
         $ev->setCancelled();


         if($item->getId() == 339)
         {

           $need = TradePlugin::$db[$player->getName()]['item1'];
           $get = TradePlugin::$db[$player->getName()]['item2'];
           $need_name = $need->getName();
           $get_name = $get->getName();
           if(!$player->getInventory()->contains($need))
           {
             $inventory->onClose($player);

             $player->sendMessage('교환에 필요한 아이템이 부족합니다.');
           }else {
             $inventory->onClose($player);

             $player->getInventory()->addItem($get);
             $player->getInventory()->removeItem($need);
             $player->sendMessage("< 교환 정보 >\n교환에 쓰인 아이템 : {$need_name}\n갯수 : {$need->getCount()}\n\n교환된 아이템 : {$get_name}\n갯수 : {$get->getCount()}");
           }
         }
       }
     }
   }
 }
}

 class TradeListInventory extends ContainerInventory
 {

   	private $plugin;
    //private $vector = array();
    private $data = [];

   	protected $size;
   	protected $title;

   	public function __construct(TradeEvent $plugin, array $items, Vector3 $holder, int $size = null, string $title = ""){

   		$this->plugin = $plugin;

   		$this->title = $title;
   		$this->size = $size;

   		parent::__construct($holder, $items, $size, $title);
   	}
   	public function onOpen(Player $player):void{

   		BaseInventory::onOpen($player);

      //$player->teleport(new Vector3(floor($player->x), floor($player->y), floor($player->z)));

   		$block = Block::get(54, 0);
   		$block->x = (int) floor($player->x);
   		$block->y = (int) floor($player->y + 4);
   		$block->z = (int) floor($player->z);
      //$this->vector[$player->getName()] = new Vector3($player->x, $player->y, $player->z);
      $this->data[$player->getName()] =
      [
        'x' => $block->x,
        'y' => $block->y,
        'z' => $block->z
      ];
   		$player->getLevel()->sendBlocks([$player], [$block]);

   		$tag = new CompoundTag();
   		$tag->setString("CustomName", $this->title);

   		$pk = new BlockActorDataPacket();
   		$pk->x = $block->x;
   		$pk->y = $block->y;
   		$pk->z = $block->z;
   		$pk->namedtag = (new NetworkLittleEndianNBTStream())->write($tag);
   		$player->sendDataPacket($pk);

   		$pk = new ContainerOpenPacket();
   		$pk->windowId = $player->getWindowId($this);
   		$pk->type = WindowTypes::CONTAINER;
   		$pk->x = $block->x;
   		$pk->y = $block->y;
   		$pk->z = $block->z;
   		$player->dataPacket($pk);
      $this->plugin->item['windowId'][$player->getName()] = $player->getWindowId($this);

      $slot_count = 0;
      foreach(TradePlugin::$db[TradePlugin::$db['trade'][$player->getName()]] as $key)
      {

        $data = TradePlugin::$db[TradePlugin::$db['trade'][$player->getName()]][$slot_count];
        $item_need = Item::jsonDeserialize($data['need']);
        $item_get = Item::jsonDeserialize($data['get']);
        $item_need_name = $item_need->getCustomName() == "" ? $item_need->getName() : $item_need->getCustomName();
        $item_get_name = $item_get->getCustomName() == "" ? $item_get->getName() : $item_get->getCustomName();
        $item_get->setLore([
          "§6▶ §f교환에 필요한 아이템 : {$item_need_name}\n§6- §f필요한 갯수 : {$item_need->getCount()}\n\n\n§6▶ §f교환될 아이템 : {$item_get_name}\n§6- §f필요한 갯수 : {$item_get->getCount()}"
        ]);
        $this->setItem($slot_count, $item_get->setCount(1));
        $slot_count++;
      }


   		$this->sendContents($player);
   	}
   	public function onClose(Player $player):void
     {

   		BaseInventory::onClose($player);
      //$player->teleport($this->vector[$player->getName()]);

      $x = $this->data[$player->getName()]['x'];//(int) floor($player->x);
      $y = $this->data[$player->getName()]['y'];//(int)floor($player->y + 4);
      $z = $this->data[$player->getName()]['z'];//(int)floor($player->z);
      $vector = new Vector3($x, $y, $z);


        $packet = new UpdateBlockPacket();
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->flags = UpdateBlockPacket::FLAG_NONE;
        $packet->blockRuntimeId = BlockFactory::toStaticRuntimeId($player->getLevel()->getBlock($vector)->getId(), $player->getLevel()->getBlock($vector)->getDamage());
        $player->sendDataPacket($packet);

        if(isset(TradePlugin::$db['인벤'][$player->getName()]))
        {
          for($i=0; $i<=35; $i++)
          {
            if(isset(TradePlugin::$db['인벤'][$player->getName()][$i]))
            {
            $item = Item::jsonDeserialize(TradePlugin::$db['인벤'][$player->getName()][$i]);
            $player->getInventory()->setItem($i, $item);
            }
          }
          unset(TradePlugin::$db['인벤'][$player->getName()]);
        }

   		//$pk = new ContainerClosePacket();
   	  //$pk->windowId = $player->getWindowId($this);
   		//$player->dataPacket($pk);

   	}
   	public function getNetworkType():int
     {

   		return WindowTypes::CONTAINER;
   	}
   	public function getName() : string
     {

   		return $this->title;
   	}
   	public function getDefaultSize() : int
     {

   		return $this->size;
   	}

 }


class TradeInventory extends ContainerInventory
{

  	private $plugin;
    private $vector;

  	protected $size;
  	protected $title;

  	public function __construct(TradeEvent $plugin, array $items, Vector3 $holder, int $size = null, string $title = ""){

  		$this->plugin = $plugin;

  		$this->title = $title;
  		$this->size = $size;

  		parent::__construct($holder, $items, $size, $title);
  	}
  	public function onOpen(Player $player):void{

  		BaseInventory::onOpen($player);

  		$block = Block::get(154, 0);
  		$block->x = (int) $player->x;
  		$block->y = (int) $player->y + 3;
  		$block->z = (int) $player->z;
  		$player->getLevel()->sendBlocks([$player], [$block]);

  		$tag = new CompoundTag();
  		$tag->setString("CustomName", $this->title);

  		$pk = new BlockActorDataPacket();
  		$pk->x = $block->x;
  		$pk->y = $block->y;
  		$pk->z = $block->z;
  		$pk->namedtag = (new NetworkLittleEndianNBTStream())->write($tag);
  		$player->sendDataPacket($pk);

  		$pk = new ContainerOpenPacket();
  		$pk->windowId = $player->getWindowId($this);
  		$pk->type = WindowTypes::HOPPER;
  		$pk->x = $block->x;
  		$pk->y = $block->y;
  		$pk->z = $block->z;
  		$player->dataPacket($pk);

      $item = Item::get(339,0,1);
      $item->setCustomName("§6▶ §f터치 §6◀");
      $lore = $item->getLore();
      $lore[] = "§6■ §f아이템 교환에 필요한 아이템을 가지고 있으시면 터치!";
      $item->setLore($lore);

      $this->setItem(1, Item::get(106,0,1));
      $this->setItem(2, $item);
      $this->setItem(3, Item::get(106,0,1));

      $this->setItem(0, TradePlugin::$db[$player->getName()]['item1']);
      $this->setItem(4, TradePlugin::$db[$player->getName()]['item2']);

  		$this->sendContents($player);
  	}
  	public function onClose(Player $player):void
    {

  		BaseInventory::onClose($player);

      $x = (int)$player->x;
      $y = (int)$player->y + 3;
      $z = (int)$player->z;
      $vector = new Vector3($x, $y, $z);


        $packet = new UpdateBlockPacket();
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->flags = UpdateBlockPacket::FLAG_NONE;
        $packet->blockRuntimeId = BlockFactory::toStaticRuntimeId($player->getLevel()->getBlock($vector)->getId(), $player->getLevel()->getBlock($vector)->getDamage());
        $player->sendDataPacket($packet);

        if(isset(TradePlugin::$db['인벤'][$player->getName()]))
        {
          for($i=0; $i<=35; $i++)
          {
            if(isset(TradePlugin::$db['인벤'][$player->getName()][$i]))
            {
            $item = Item::jsonDeserialize(TradePlugin::$db['인벤'][$player->getName()][$i]);
            $player->getInventory()->setItem($i, $item);
            }
          }
          unset(TradePlugin::$db['인벤'][$player->getName()]);
        }


  	}
  	public function getNetworkType():int
    {

  		return WindowTypes::HOPPER;
  	}
  	public function getName() : string
    {

  		return $this->title;
  	}
  	public function getDefaultSize() : int
    {

  		return $this->size;
  	}

}

class SubClass
{

  public function titleName() : string
  {
    return "Trade UI";
  }

  public function SpawnNpc(Player $player, $name): void
  {

      $nbt = new CompoundTag('', [
          new StringTag("CustomName", $name),
          new ListTag('Pos', [
              new DoubleTag('', $player->getX()),
              new DoubleTag('', $player->getY()),
              new DoubleTag('', $player->getZ())
          ]),
          new ListTag('Motion', [
              new DoubleTag('', 0),
              new DoubleTag('', 0),
              new DoubleTag('', 0)
          ]),
          new ListTag('Rotation', [
              new FloatTag('', $player->getYaw()),
              new FloatTag('', $player->getPitch())
          ]),
          new CompoundTag('Skin', [
              new StringTag("Name", $player->getSkin()->getSkinId()),
              new StringTag('Data', $player->getSkin()->getSkinData())
          ])
      ]);
      $entity = Entity::createEntity("TradeNpc", $player->getLevel(), $nbt);
      $entity->setNameTag($name);
      $entity->spawnToAll();
    }

}

class TradeMainUI implements Form
{

  public function jsonSerialize() : array
  {

    $title = new SubClass;

    return [

      'type' => 'form',
      'title' => $title->titleName(),
      'content' => '§6- Button List',
      'buttons' => [
        [
          'text' => 'need Item'
        ],
        [
          'text' => 'get Item'
        ],
        [
          'text' => 'Create Trade'
        ],
        [
          'text' => 'Spawn NPC'
        ]
      ]
    ];
  }

  public function handleResponse(Player $player, $data) : void
  {

    if(is_int($data))
    {

      $config = TradePlugin::$db;
      $sub_config = TradePlugin::$sub;
      $item = $player->getInventory()->getItemInHand();

      switch ($data)
      {
        case 0:
        $item_name = $item->getCustomName() == "" ? $item->getName(): $item->getCustomName();
        TradePlugin::$sub[$player->getName()]['item1'] = $item->jsonSerialize();
        $player->sendMessage("< 교환에 필요한 아이템 정보 >\n아이템 이름 : {$item_name}\n아이템 갯수 : {$item->getCount()}");
        break;
        case 1:
        $item_name = $item->getCustomName() == "" ? $item->getName(): $item->getCustomName();
        TradePlugin::$sub[$player->getName()]['item2'] = $item->jsonSerialize();
        $player->sendMessage("< 교환해 가질 아이템 정보 >\n아이템 이름 : {$item_name}\n아이템 갯수 : {$item->getCount()}");
        break;
        case 2:
        if(!isset($sub_config[$player->getName()]['item1']) or !isset($sub_config[$player->getName()]['item2']))
        {

          $player->sendMessage("교환에 필요한, 얻을 아이템을 설정 해주시고 다시 시도 해주세요.");
        }else
        {

          TradePlugin::$db[$player->getName()]['all'] = [
            'item1' => TradePlugin::$sub[$player->getName()]['item1'],
            'item2' => TradePlugin::$sub[$player->getName()]['item2']
          ];

          TradePlugin::$data->setAll(TradePlugin::$db);
          TradePlugin::$data->save();
          $player->sendMessage("Trade NPC를 터치해주세요.");
        }
        break;
        case 3:
          $player->sendForm(new TradeUI());
        break;
        }

      }

    }

}

class subUI implements Form
{

  public function jsonSerialize() : array
  {

    $title = new SubClass;

    return [

      'type' => 'form',
      'title' => $title->titleName(),
      'content' => '교환을 진행하시려면 버튼을 눌러주세요.',
      'buttons' => [
        [
          'text' => '교환'
        ]
      ]
    ];
  }

  public function handleResponse(Player $player, $data) : void
  {

    if(is_int($data))
    {

      if($data == 0)
      {
        TradeEvent::TradeOpen($player);
      }
    }
  }

}

class TradeUI implements Form
{

  public function jsonSerialize() : array
  {

    $title = new SubClass;

    return [

      'type' => 'custom_form',
      'title' => $title->titleName(),
      'content' => [
        [
          'type' => 'input',
          'text' => 'NPC NAME'
        ]
      ]
    ];
  }

  public function handleResponse(Player $player, $data) : void
  {

    if(is_array($data))
    {

      if(isset($data[0]))
      {
        $class = new SubClass();
        $class->SpawnNpc($player, $data[0]);
      }

    }
  }

}

class TradeNPC extends Human
{
}
