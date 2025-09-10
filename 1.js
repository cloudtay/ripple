async function func_a(){
    return 'hello world';
}

async function func_b(){
    return func_a();
}

async function func_c(){
    console.log(func_b());
    return func_b();
}

async function main(){
    console.log(await func_c())
}

main();