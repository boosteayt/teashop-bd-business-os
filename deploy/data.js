window.TSB_DATA={
users:[
{name:"Shahidur Rahman",email:"owner@teashop.bd",role:"OWNER",label:"Founder / Owner / CEO"},
{name:"Md. Omar Faruk",email:"faruk@teashop.bd",role:"OPERATIONS",label:"Head of Franchise & Retail Operations"},
{name:"Finance Manager",email:"finance@teashop.bd",role:"FINANCE",label:"Finance & Accounts"},
{name:"Warehouse Manager",email:"warehouse@teashop.bd",role:"WAREHOUSE",label:"Warehouse & Inventory"},
{name:"Regional Manager",email:"regional@teashop.bd",role:"REGIONAL",label:"Regional Operations"},
{name:"Franchise Owner",email:"franchise@teashop.bd",role:"FRANCHISE",label:"Franchise Owner"},
{name:"POS Cashier",email:"cashier@teashop.bd",role:"CASHIER",label:"POS / Cashier"}
],
marginTiers:{Starter:25,Growth:27,Elite:30,Manual:30},
farukTiers:{Base:15,Growth:20,Elite:25,Manual:20},
assumptions:{tube30:60,pouch50:18,pouch100:22,ctc250:24,ctc500:28,labour:5,overhead:7,wastagePct:3,logistics:4,taxProvisionPct:0},
products:[
["CTC / Black Tea","Royal Gold",450],["CTC / Black Tea","Grand Reserve",400],["CTC / Black Tea","Bold Reserve",400],["CTC / Black Tea","Classic Blend",370],["CTC / Black Tea","Golden Cup",360],["CTC / Black Tea","Prime Select",350],["CTC / Black Tea","Imperial Blend",350],["CTC / Black Tea","Heritage Gold",400],["CTC / Black Tea","Signature Blend",400],["CTC / Black Tea","Supreme Gold",400],["CTC / Black Tea","Premium Blend Tea",380],["CTC / Black Tea","Clone Tea",370],["CTC / Black Tea","BT-2 Tea",350],["CTC / Black Tea","Tea Gold Tea",350],["CTC / Black Tea","Golden Tea",350],["CTC / Black Tea","Premium CTC Luxury Tea",600],
["Leaf Black Tea","Golden Leaf",1800],["Leaf Black Tea","Royal Reserve",1900],["Leaf Black Tea","Obsidara Estate",2000],["Leaf Black Tea","Poppy Roll Black Tea",2000],["Leaf Black Tea","Estate Leaf",2000],["Leaf Black Tea","Highland Leaf",1800],["Leaf Black Tea","Heritage Leaf",1800],["Leaf Black Tea","Mountain Reserve",1800],["Leaf Black Tea","Signature Leaf",1800],["Leaf Black Tea","Premium Estate",1800],["Leaf Black Tea","Finest Leaf",1800],["Leaf Black Tea","Grand Leaf",1800],
["Green Tea","Premium Green Tea",1400],["Green Tea","BTRI Green Tea",2000],["Green Tea","Dragon Well Tea",5000],["Green Tea","Sencha Green Tea",4000],["Green Tea","Moondrop Tea",2000],["Green Tea","Exclusive Green Tea",2000],["Green Tea","Green Ball Tea",2000],["Green Tea","Pu-Erh Tea",3000],
["Classic Green Tea","Pure Green",1400],["Classic Green Tea","Emerald Green",1600],["Classic Green Tea","Zen Leaf",1500],["Classic Green Tea","Green Harmony",1600],["Classic Green Tea","Vital Green",1800],["Classic Green Tea","Nature Pure",1700],["Classic Green Tea","Fresh Balance",1500],["Classic Green Tea","Green Essence",1800],
["Orthodox Tea","Imperial Estate",1200],["Orthodox Tea","Estate Reserve",1400],["Orthodox Tea","Highland Gold",1500],["Orthodox Tea","Royal Orthodox",2500],["Orthodox Tea","Grand Estate",1200],["Orthodox Tea","Signature Estate",1500],["Orthodox Tea","Heritage Orthodox",2500],["Orthodox Tea","Orthodox Tea",1400],["Orthodox Tea","Orthodox Black Tea",1400],["Orthodox Tea","Orthodox Green Tea",1600],["Orthodox Tea","Leaf Orthodox Tea",1400],["Orthodox Tea","Fenning Orthodox Tea",1800],["Orthodox Tea","Summit Orthodox",3000],["Orthodox Tea","Prestige Orthodox",3000],
["Oolong Tea","Amber Oolong",1500],["Oolong Tea","Silk Oolong",1800],["Oolong Tea","Imperial Oolong",1700],["Oolong Tea","Mountain Oolong",1800],["Oolong Tea","Golden Oolong",1600],["Oolong Tea","Oolong Tea",1800],
["Yellow Tea","Golden Dawn",1500],["Yellow Tea","Lumora Yellow Tea",3000],["Yellow Tea","Sunvera Yellow Tea",1800],["Yellow Tea","Golden Mist",1800],["Yellow Tea","Imperial Yellow",2500],["Yellow Tea","Yellow Pearl",2000],["Yellow Tea","Rare Gold",2000],["Yellow Tea","Yellow Tea",2500],
["White Tea","White Pearl",14000],["White Tea","Silver Mist",12000],["White Tea","White Blossom",17000],["White Tea","Ivory Leaf",13000],["White Tea","Pure White",15000],["White Tea","White Tea",14000],["White Tea","Silver Needles",17000],["White Tea","Jasmine Bai Mu Dan Tea",5000],
["Flavored / Scented Tea","Lemon Zest",2200],["Flavored / Scented Tea","Ginger Spice",2000],["Flavored / Scented Tea","Mint Breeze",2000],["Flavored / Scented Tea","Spice Symphony",2000],["Flavored / Scented Tea","Cinnamon Delight",2000],["Flavored / Scented Tea","Citrus Bloom",2500],["Flavored / Scented Tea","Rose Tea",2500],["Flavored / Scented Tea","Jasmine Tea",3000],["Flavored / Scented Tea","Scarlet Bloom",2500],
["Fruit Tea","Golden Bloom",3000],["Fruit Tea","Sunrise Mist",2600],["Fruit Tea","Ruby Whisper",2000],["Fruit Tea","Velvet Dawn",1600],["Fruit Tea","Tropical Aura",1700],
["Herbal Tea","Butterfly Tea",2300],["Herbal Tea","Rosella Tea",1400],["Herbal Tea","Peppermint Tea",1800],["Herbal Tea","Rosemarry Tea",1500],["Herbal Tea","Chamomile Tea",8000]
].map((x,i)=>({id:i+1,category:x[0],name:x[1],costKg:x[2]}))
};